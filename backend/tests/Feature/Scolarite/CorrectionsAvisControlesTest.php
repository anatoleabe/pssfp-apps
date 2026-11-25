<?php

declare(strict_types=1);

use App\Filament\Resources\CorrectionVersementResource;
use App\Filament\Resources\CorrectionVersementResource\Pages\ListCorrectionsVersements;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\CorrectionVersement;
use App\Models\DemandeAutorisation;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Scolarite\AvisRecus;
use App\Services\Scolarite\CorrectionsVersements;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Supervision\Controles\RcptContinuite;
use App\Services\Supervision\Controles\SecSeparation;
use App\Services\Supervision\Controles\VerCorrections;
use App\Support\AppSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Correction des versements, sous-lot F4b (spec module-4-scolarite-espace-financier §7.4 R-C10 et R-C11, §7.8, §10 ;
 * décisions Q4 et Q5 du 16/11/2026) : avis à l'auditeur, reçu remplacé dans son espace, écran « Corrections », contrôles
 * VER_CORRECTIONS et RCPT_CONTINUITE, couple « demander et décider » de SEC_SEPARATION.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    Mail::fake();
    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = ['tel' => $tel, 'texte' => $texte];
        });
    });
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->administrateur = ($this->compte)('admin');
    $this->financier = ($this->compte)('service_financier');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $dossier = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel,
        'email' => mb_strtolower($nom).'-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
        'user_id' => User::factory()->create(['phone_e164' => $tel, 'email' => Str::random(8).'@example.org'])->id,
    ]);
    $this->a = $dossier('Alpha', '+237699001001');
    $this->b = $dossier('Beta', '+237699002002');
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    $this->a->refresh();
    $this->b->refresh();
    $this->encaisser = fn (Candidature $c, array $champs = []): Versement => app(RegistreVersements::class)->encaisser($c->auditeur->inscriptionCourante, $champs + [
        'montant' => 100000, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur',
    ], $this->financier)->versement;
    $this->corriger = function (Versement $v, array $parametres, array $options = []): CorrectionVersement {
        $d = app(Demandes::class)->soumettre($this->financier, TypesDemande::CORRIGER_VERSEMENT, $v->id, 'Erreur de saisie au guichet du service financier', null, $parametres);
        app(Demandes::class)->decider($this->administrateur, $d, true, null, null, $options);

        return CorrectionVersement::query()->latest('id')->firstOrFail();
    };
    $this->smsA = fn (): array => collect($this->sms)->where('tel', '+237699001001')->pluck('texte')->values()->all();
    $this->smsB = fn (): array => collect($this->sms)->where('tel', '+237699002002')->pluck('texte')->values()->all();
    $this->jeton = fn (Candidature $c): string => $c->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;
});

describe('avis à l\'auditeur (R-C10)', function (): void {
    it('annonce le nouveau reçu en citant celui qu\'il remplace', function (): void {
        $v = ($this->encaisser)($this->a, ['reference' => 'BOR-FAUX']);
        $ancien = $v->recuEnVigueur()->numero;
        $this->sms = [];

        $c = ($this->corriger)($v, ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-JUSTE']);
        $nouveau = $c->recuRemplacant->numero;

        expect(($this->smsA)())->toBe(["PSSFP : votre recu {$nouveau} de 100000 FCFA remplace le recu {$ancien}, annule. Il est dans votre espace https://apply.pssfp.org"])
            ->and(CandidatureRelance::query()->where('candidature_id', $this->a->id)->where('cause', CandidatureRelance::CAUSE_CORRECTION)->pluck('canal')->sort()->values()->all())
            ->toBe(['email', 'sms']);
    });

    it('prévient de l\'annulation seule, avec ou sans reçu', function (): void {
        $v = ($this->encaisser)($this->a, ['montant' => 75000]);
        $numero = $v->recuEnVigueur()->numero;
        $this->sms = [];
        ($this->corriger)($v, ['nature' => 'annulation', 'categorie' => 'doublon']);
        expect(($this->smsA)())->toBe(["PSSFP : votre recu {$numero} de 75000 FCFA a ete annule par le service financier. Votre situation est dans votre espace https://apply.pssfp.org"]);

        // Sans reçu : versement enregistré seul (le reçu n'a pas été émis).
        $sansRecu = app(RegistreVersements::class)->enregistrer($this->b->auditeur->inscriptionCourante, [
            'montant' => 60000, 'date_versement' => '2026-11-08', 'mode' => 'virement', 'reference' => 'VIR-1', 'payeur' => 'auditeur',
        ], $this->financier)->versement;
        app(CorrectionsVersements::class)->annulerDirectement($sansRecu, 'rejet_banque', 'Virement rejeté par la banque émettrice', $this->administrateur);
        expect(($this->smsB)())->toBe(['PSSFP : votre versement du 08/11/2026 de 60000 FCFA a ete annule par le service financier. Votre situation est dans votre espace https://apply.pssfp.org']);
    });

    it('prévient l\'auditeur qui perd le versement, et n\'annonce à l\'autre que son propre reçu', function (): void {
        $v = ($this->encaisser)($this->a);
        $ancien = $v->recuEnVigueur()->numero;
        $this->sms = [];
        $c = ($this->corriger)($v, ['nature' => 'remplacement', 'categorie' => 'auditeur', 'auditeur_id' => $this->b->auditeur->id]);

        expect(($this->smsA)()[0])->toContain('a ete annule')->toContain($ancien)
            ->and(($this->smsB)())->toBe(['PSSFP : votre recu '.$c->recuRemplacant->numero.' de 100000 FCFA est disponible dans votre espace https://apply.pssfp.org']);
    });

    it('écrit des SMS ASCII d\'un seul segment, l\'adresse en fin de message, même pour le plus gros montant', function (): void {
        foreach ([
            AvisRecus::texteSmsRemplacement('RCPT-2026-000123', RegistreVersements::MONTANT_MAX, 'RCPT-2026-000122'),
            AvisRecus::texteSmsAnnulation('RCPT-2026-000123', RegistreVersements::MONTANT_MAX, '08/11/2026'),
            AvisRecus::texteSmsAnnulation(null, RegistreVersements::MONTANT_MAX, '08/11/2026'),
        ] as $texte) {
            expect(mb_strlen($texte))->toBeLessThanOrEqual(160)
                ->and(mb_check_encoding($texte, 'ASCII'))->toBeTrue()
                ->and($texte)->toEndWith('https://apply.pssfp.org');
        }
    });

    it('n\'envoie rien quand la correction est refusée', function (): void {
        $v = ($this->encaisser)($this->a);
        $this->sms = [];
        $d = app(Demandes::class)->soumettre($this->financier, TypesDemande::CORRIGER_VERSEMENT, $v->id, 'Erreur de saisie au guichet du service financier', null,
            ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-AUTRE']);
        app(RecusVersements::class)->annuler($v->recuEnVigueur(), 'Reçu annulé entre-temps', $this->administrateur);
        app(Demandes::class)->decider($this->administrateur, $d, true);

        expect($d->refresh()->statut)->toBe(DemandeAutorisation::ECHEC)->and($this->sms)->toBe([]);
    });
});

describe('espace de l\'auditeur (R-C11)', function (): void {
    it('montre le nouveau reçu, le reçu annulé qu\'il remplace, téléchargeable, et jamais l\'annulation seule', function (): void {
        $v = ($this->encaisser)($this->a, ['reference' => 'BOR-UN']);
        $ancien = $v->recuEnVigueur();
        $c = ($this->corriger)($v, ['nature' => 'remplacement', 'categorie' => 'reference', 'reference' => 'BOR-DEUX']);
        $doublon = ($this->encaisser)($this->a, ['reference' => 'BOR-DOUBLON']);
        app(CorrectionsVersements::class)->annulerDirectement($doublon, 'doublon', 'Doublon constaté au guichet de la scolarité', $this->administrateur);

        $versements = $this->withToken(($this->jeton)($this->a))->getJson('/v1/applications/me/scolarite')->assertOk()->json('data.versements');
        expect($versements)->toHaveCount(1)
            ->and($versements[0]['reference'])->toBe('BOR-DEUX')
            ->and($versements[0]['recu']['numero'])->toBe($c->recuRemplacant->numero)
            ->and($versements[0]['remplace'])->toBe(['uuid' => $ancien->uuid, 'numero' => $ancien->numero, 'annule_le' => '2026-11-10']);
        $this->withToken(($this->jeton)($this->a))->get('/v1/applications/me/scolarite/recus/'.$ancien->uuid)->assertOk();
    });

    it('ne montre jamais à un auditeur le reçu d\'un autre', function (): void {
        $v = ($this->encaisser)($this->a);
        ($this->corriger)($v, ['nature' => 'remplacement', 'categorie' => 'auditeur', 'auditeur_id' => $this->b->auditeur->id]);

        $versements = $this->withToken(($this->jeton)($this->b))->getJson('/v1/applications/me/scolarite')->assertOk()->json('data.versements');
        expect($versements[0]['remplace'])->toBeNull();
    });
});

describe('contrôle interne', function (): void {
    it('relève les baisses et les annulations de versements confirmés, et les comptes au-delà du seuil', function (): void {
        $baisse = ($this->encaisser)($this->a, ['montant' => 90000]);
        confirmerVersement($baisse);
        ($this->corriger)($baisse, ['nature' => 'remplacement', 'categorie' => 'montant', 'montant' => 80000], [CorrectionsVersements::OPTION_ATTESTATION => true]);
        $hausse = ($this->encaisser)($this->b, ['montant' => 50000]);
        confirmerVersement($hausse);
        ($this->corriger)($hausse, ['nature' => 'remplacement', 'categorie' => 'montant', 'montant' => 60000], [CorrectionsVersements::OPTION_ATTESTATION => true]);
        $annule = ($this->encaisser)($this->b, ['montant' => 20000]);
        confirmerVersement($annule);
        ($this->corriger)($annule, ['nature' => 'annulation', 'categorie' => 'doublon']);

        $cas = app(VerCorrections::class)->cas();
        expect(array_keys(array_filter($cas, fn (array $c): bool => isset($c['correction']))))->toHaveCount(2)
            ->and(collect($cas)->pluck('resume')->implode(' '))->toContain('montant baissé')->toContain('annulé sans remplacement')->not->toContain('Alpha');

        AppSettings::set(VerCorrections::CLE_SEUIL, '2');
        expect(app(VerCorrections::class)->cas())->toHaveKey('compte:'.$this->financier->id);
    });

    it('signale un numéro de reçu manquant, et rien quand la numérotation est continue', function (): void {
        ($this->encaisser)($this->a);
        ($this->encaisser)($this->b);
        expect(app(RcptContinuite::class)->cas())->toBe([]);

        DB::table('compteurs_recus')->where('annee', 2026)->increment('dernier');
        expect(app(RcptContinuite::class)->cas())->toHaveKey('RCPT-2026');
    });

    it('signale qui peut à la fois demander et décider une correction', function (): void {
        $cumul = ($this->compte)('service_financier');
        $cumul->givePermissionTo(CorrectionsVersements::PERMISSION_DECIDER);

        expect(collect(app(SecSeparation::class)->cas())->filter(fn (array $c): bool => $c['compte'] === $cumul->id
            && $c['couple'] === 'Demander et décider une correction de versement'))->toHaveCount(1);
    });
});

describe('écran « Corrections »', function (): void {
    it('liste les pièces pour l\'espace Finances, compte les demandes en attente et exporte', function (): void {
        $v = ($this->encaisser)($this->a);
        $c = ($this->corriger)($v, ['nature' => 'annulation', 'categorie' => 'doublon']);
        $autre = ($this->encaisser)($this->b);
        app(Demandes::class)->soumettre($this->financier, TypesDemande::CORRIGER_VERSEMENT, $autre->id, 'Erreur de saisie au guichet du service financier', null,
            ['nature' => 'annulation', 'categorie' => 'doublon']);

        $this->actingAs($this->financier);
        expect(CorrectionVersementResource::getNavigationBadge())->toBe('1');
        $this->livewire(ListCorrectionsVersements::class)
            ->assertCanSeeTableRecords([$c])
            ->assertSee('1 demande(s) de correction en attente')
            ->callAction('exporter')
            ->assertFileDownloaded();

        $this->actingAs(($this->compte)('scolarite_agent'));
        expect(CorrectionVersementResource::canAccess())->toBeFalse();
    });
});
