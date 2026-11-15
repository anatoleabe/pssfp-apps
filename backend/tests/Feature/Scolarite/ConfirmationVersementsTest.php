<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\AuditeurResource\RelationManagers\VersementsRelationManager;
use App\Filament\Resources\VersementAConfirmerResource;
use App\Filament\Resources\VersementAConfirmerResource\Pages\ListVersementsAConfirmer;
use App\Mail\NotificationCandidatMail;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\ConfirmationVersement;
use App\Models\DeblocageAcces;
use App\Models\EngagementPriseEnCharge;
use App\Models\InscriptionAnnuelle;
use App\Models\RecuVersement;
use App\Models\Role;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\ConfirmationsVersements;
use App\Services\Scolarite\DeblocagesAcces;
use App\Services\Scolarite\EngagementsPriseEnCharge;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\OuvertureAuditeur;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\ReglementsOrganismes;
use App\Services\Scolarite\RelancesConfirmations;
use App\Services\Scolarite\RepriseConfirmations;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Supervision\Supervision;
use App\Support\AppSettings;
use App\Support\CandidatureObjectStorage;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Confirmation des versements par la scolarité (spec module-4-scolarite-encaissement-confirmation §2.2,
 * décisions du 15/11/2026) : seul l'argent confirmé rend un auditeur « à jour ».
 */

uses()->group('scolarite');

const MIGRATION_CONFIRMER = '2026_12_04_100300_grant_scolarite_versement_confirmer.php';

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    Mail::fake();
    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = $texte;
        });
    });

    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel, 'email' => Str::lower($nom).'-'.Str::random(4).'@example.org',
        'ecampus_statut' => ComptesEcampus::CREE,
        'user_id' => User::factory()->create(['phone_e164' => $tel, 'email' => Str::random(8).'@example.org'])->id,
    ]);
    $this->awa = $retenu('Abena', '+237699006001');
    $this->bruno = $retenu('Bekono', '+237699006002');
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    $this->awa->refresh();
    $this->bruno->refresh();
    $this->inscription = fn (Candidature $c): InscriptionAnnuelle => $c->fresh()->auditeur->inscriptionCourante;
    $this->encaisser = fn (int $montant = 185000, ?Candidature $c = null, ?User $par = null): Versement => app(RegistreVersements::class)->encaisser(($this->inscription)($c ?? $this->awa),
        ['montant' => $montant, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur'], $par ?? $this->financier)->versement;
    $this->scan = fn (): string => 'scolarite/justificatifs/'.Str::ulid().'.pdf';
    $this->confirmer = fn (Versement $v, ?User $par = null, ?string $scan = null): array => app(ConfirmationsVersements::class)
        ->confirmer($v, $scan ?? ($this->scan)(), true, $par ?? $this->agent)->confirmations;
    $this->etat = fn (Candidature $c): string => AccesEcampus::etat(Candidature::query()->findOrFail($c->id));
    $this->smsAcces = fn (): array => array_values(array_filter($this->sms, fn (string $t): bool => str_contains($t, 'acces FOAD')));
});

describe('confirmation', function (): void {
    it('confirme un versement avec le scan et l\'attestation, une seule fois, et la trace', function (): void {
        $v = ($this->encaisser)();
        $scan = ($this->scan)();

        [$c] = ($this->confirmer)($v, scan: $scan);

        expect($c->versement_id)->toBe($v->id)
            ->and($c->confirme_par)->toBe($this->agent->id)
            ->and($c->justificatif_path)->toBe($scan)
            ->and($c->attestation)->toBeTrue()
            ->and($c->reprise)->toBeFalse()
            ->and($v->fresh()->justificatif_path)->toBe($scan)
            ->and(Activity::query()->where('event', 'versement_confirme')->where('subject_id', $v->id)->sole()->log_name)->toBe('scolarite')
            ->and(fn () => ($this->confirmer)($v->fresh(), $this->responsable))->toThrow(InvalidArgumentException::class, 'déjà confirmé');

        // Définitive jusque dans la base : chaque tentative dans son point de sauvegarde.
        expect(fn () => DB::transaction(fn () => ConfirmationVersement::query()->whereKey($c->id)->update(['attestation' => false])))->toThrow(QueryException::class, 'immuable')
            ->and(fn () => DB::transaction(fn () => DB::table('confirmations_versements')->where('id', $c->id)->delete()))->toThrow(QueryException::class, 'immuable');
    });

    it('refuse sans scan, sans attestation, sans le droit, pour son propre versement ou un versement annulé', function (): void {
        $v = ($this->encaisser)();
        $service = app(ConfirmationsVersements::class);

        expect(fn () => $service->confirmer($v, null, true, $this->agent))->toThrow(InvalidArgumentException::class, 'scan')
            ->and(fn () => $service->confirmer($v, ($this->scan)(), false, $this->agent))->toThrow(InvalidArgumentException::class, 'J\'atteste')
            ->and(fn () => $service->confirmer($v, 'autre/dossier/piece.pdf', true, $this->agent))->toThrow(InvalidArgumentException::class, 'scan')
            ->and(fn () => $service->confirmer($v, ($this->scan)(), true, $this->financier))->toThrow(HttpException::class);

        // Personne ne confirme un versement qu'il a lui-même saisi.
        $admin = ($this->compte)('admin');
        $sien = app(RegistreVersements::class)->enregistrer(($this->inscription)($this->bruno), ['montant' => 10000, 'date_versement' => '2026-11-09',
            'mode' => 'especes', 'reference' => 'CAISSE-1', 'payeur' => 'auditeur'], $admin)->versement;
        expect(fn () => $service->confirmer($sien, ($this->scan)(), true, $admin))->toThrow(InvalidArgumentException::class, 'Vous avez enregistré ce versement');

        // Un scan déjà joint ailleurs ne sert pas deux fois.
        $deja = ($this->scan)();
        ($this->confirmer)($sien, scan: $deja);
        expect(fn () => ($this->confirmer)($v, scan: $deja))->toThrow(InvalidArgumentException::class, 'déjà joint');

        app(RegistreVersements::class)->annuler($v, 'Bordereau rejeté par la banque', $this->responsable);
        expect(fn () => ($this->confirmer)($v->fresh()))->toThrow(InvalidArgumentException::class, 'annulé')
            ->and(ConfirmationVersement::query()->where('versement_id', $v->id)->exists())->toBeFalse();
    });
});

describe('effet « à jour »', function (): void {
    it('n\'ouvre l\'accès qu\'à la confirmation qui solde les droits, et envoie alors le SMS des accès', function (): void {
        $v = ($this->encaisser)(185000);
        $s = ($this->inscription)($this->awa)->situation();

        expect(($this->etat)($this->awa))->toBe(AccesEcampus::BLOQUE)
            ->and($s->verse)->toBe(185000)
            ->and($s->aConfirmer)->toBe(185000)
            ->and($s->inscriptionSoldee())->toBeFalse()
            ->and(($this->smsAcces)())->toBe([]);

        ($this->confirmer)($v);

        expect(($this->etat)($this->awa))->toBe(AccesEcampus::DISPONIBLE)
            ->and(($this->inscription)($this->awa)->situation()->aConfirmer)->toBe(0)
            ->and(($this->smsAcces)())->toHaveCount(1)
            ->and(($this->inscription)($this->awa)->acces_notifie_at)->not->toBeNull();
    });

    it('régularise l\'accès anticipé à la confirmation, et non plus à la saisie', function (): void {
        app(DeblocagesAcces::class)->accorder(($this->inscription)($this->awa), ['type' => DeblocageAcces::MORATOIRE,
            'motif' => 'Délai accordé sur demande écrite', 'echeance' => '2026-12-15'], $this->responsable);
        $v = ($this->encaisser)(185000);
        $deblocage = fn (): DeblocageAcces => DeblocageAcces::query()->sole();

        expect($deblocage()->statut)->toBe(DeblocageAcces::EN_COURS);

        ($this->confirmer)($v);
        expect($deblocage()->statut)->toBe(DeblocageAcces::REGULARISE);
    });

    it('filtre en base comme en PHP sur les seuls montants confirmés, et garde le compte entier', function (): void {
        $i = ($this->inscription)($this->awa);
        $present = fn (string $scope): bool => InscriptionAnnuelle::query()->whereKey($i->id)->{$scope}()->exists();
        Versement::factory()->create(['inscription_annuelle_id' => $i->id, 'montant' => 185000]);
        $v = ($this->encaisser)(1000000);

        $s = $i->fresh()->situation();
        expect($s->verse)->toBe(1185000)
            ->and($s->aConfirmer)->toBe(1000000)
            ->and($s->reste)->toBe(0)
            ->and($s->inscriptionSoldee())->toBeTrue()->and($present('inscriptionSoldee'))->toBeTrue()
            ->and($s->solde())->toBeFalse()->and($present('entierementSoldee'))->toBeFalse()
            ->and(LibelleSituation::cle($i->fresh()))->not->toBe(LibelleSituation::SOLDEE);

        ($this->confirmer)($v);
        expect($i->fresh()->situation()->solde())->toBeTrue()
            ->and($present('entierementSoldee'))->toBeTrue()
            ->and(LibelleSituation::cle($i->fresh()))->toBe(LibelleSituation::SOLDEE);
    });

    it('montre à l\'auditeur, dans son espace, la part à confirmer et la date de vérification', function (): void {
        $v = ($this->encaisser)(185000);
        $jeton = fn (): string => $this->awa->user->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;

        $this->withToken($jeton())->getJson('/v1/applications/me/scolarite')->assertOk()
            ->assertJsonPath('data.verse', 185000)
            ->assertJsonPath('data.a_confirmer', 185000)
            ->assertJsonPath('data.versements.0.confirme_le', null);

        ($this->confirmer)($v);
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton())->getJson('/v1/applications/me/scolarite')->assertOk()
            ->assertJsonPath('data.a_confirmer', 0)
            ->assertJsonPath('data.versements.0.confirme_le', '2026-11-10');
    });
});

describe('règlement d\'un organisme', function (): void {
    it('confirme d\'un coup les parts du règlement, et régularise alors la prise en charge', function (): void {
        $engagements = app(EngagementsPriseEnCharge::class);
        $e = $engagements->creer(['organisme' => 'Ministère de test', 'lettre_reference' => 'LT-2026-001', 'lettre_date' => '2026-11-09',
            'lettre_path' => 'scolarite/justificatifs/01JLETTRE0000000000000000A.pdf', 'contact_email' => 'rh@organisme.example',
            'portee' => EngagementPriseEnCharge::DROITS_INSCRIPTION, 'annee_academique' => '2026-2027', 'echeance' => '2026-12-31'], $this->agent);
        $engagements->rattacher($e, [$this->awa->fresh()->auditeur, $this->bruno->fresh()->auditeur], $this->agent);
        app(ReglementsOrganismes::class)->enregistrer($e, ['montant' => 370000, 'date_reglement' => '2026-11-09', 'mode' => 'virement', 'reference' => 'OV-77',
            'parts' => ReglementsOrganismes::partsProposees($e)], $this->financier);
        $parts = Versement::query()->whereNotNull('reglement_organisme_id')->orderBy('id')->get();

        expect(DeblocageAcces::query()->pluck('statut')->unique()->all())->toBe([DeblocageAcces::EN_COURS]);

        $confirmations = ($this->confirmer)($parts->first());

        expect($confirmations)->toHaveCount(2)
            ->and(ConfirmationVersement::query()->whereIn('versement_id', $parts->pluck('id'))->count())->toBe(2)
            ->and(DeblocageAcces::query()->pluck('statut')->unique()->all())->toBe([DeblocageAcces::REGULARISE])
            // La pièce est celle du règlement : elle ne se joint pas aux versements, chacun le leur.
            ->and($parts->map(fn (Versement $p): ?string => $p->fresh()->justificatif_path)->filter()->all())->toBe([]);
    });
});

describe('écrans', function (): void {
    it('liste pour la scolarité les versements à confirmer, avec la pastille, et confirme depuis l\'écran', function (): void {
        Storage::fake(CandidatureObjectStorage::DISK);
        $v = ($this->encaisser)(185000);
        $confirme = ($this->encaisser)(50000, $this->bruno);
        ($this->confirmer)($confirme);

        $this->actingAs($this->agent);
        expect(VersementAConfirmerResource::getNavigationBadge())->toBe('1');
        $this->livewire(ListVersementsAConfirmer::class)
            ->assertCanSeeTableRecords([$v])
            ->assertCanNotSeeTableRecords([$confirme])
            ->callTableAction('confirmer', $v, data: [
                'justificatif_path' => UploadedFile::fake()->createWithContent('bordereau.pdf', "%PDF-1.4\n%%EOF\n"),
                'attestation' => true,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Versement confirmé');

        expect(ConfirmationVersement::query()->where('versement_id', $v->id)->value('confirme_par'))->toBe($this->agent->id)
            ->and(VersementAConfirmerResource::getNavigationBadge())->toBeNull();

        $this->actingAs($this->financier);
        $this->get(VersementAConfirmerResource::getUrl('index'))->assertForbidden();
    });

    it('exige l\'attestation dans le formulaire', function (): void {
        Storage::fake(CandidatureObjectStorage::DISK);
        $v = ($this->encaisser)(185000);

        $this->actingAs($this->agent);
        $this->livewire(ListVersementsAConfirmer::class)
            ->callTableAction('confirmer', $v, data: [
                'justificatif_path' => UploadedFile::fake()->createWithContent('bordereau.pdf', "%PDF-1.4\n%%EOF\n"),
                'attestation' => false,
            ])
            ->assertHasTableActionErrors(['attestation']);

        expect(ConfirmationVersement::query()->exists())->toBeFalse();
    });

    it('propose la confirmation sur la fiche à la scolarité, jamais à qui a saisi le versement', function (): void {
        $v = ($this->encaisser)(185000);
        $onglet = fn () => $this->livewire(VersementsRelationManager::class, ['ownerRecord' => $this->awa->fresh()->auditeur, 'pageClass' => ViewAuditeur::class]);

        $this->actingAs($this->agent);
        $onglet()->assertTableActionVisible('confirmerVersement', $v);
        $this->actingAs($this->financier);
        $onglet()->assertTableActionHidden('confirmerVersement', $v);
    });
});

describe('relance', function (): void {
    it('relance l\'auditeur au 7e jour, puis au plus tous les 7 jours, et signale le versement au contrôle interne', function (): void {
        $v = ($this->encaisser)(185000);
        $relances = fn (): array => array_values(array_filter($this->sms, fn (string $t): bool => $t === RelancesConfirmations::TEXTE_SMS));
        $controle = fn (): array => app(Supervision::class)->controles()['VER_NON_CONFIRMES']->cas();
        $a = fn (int $jour) => $this->travelTo(now('Africa/Douala')->setDate(2026, 11, $jour)->setTime(8, 0));
        $relancer = fn () => $this->artisan('scolarite:relancer-confirmations')->assertSuccessful();

        $a(16);
        $relancer();
        expect($relances())->toBe([])->and($controle())->toBe([]);

        $a(17);
        $relancer();
        expect($relances())->toHaveCount(1)
            ->and(Mail::sent(NotificationCandidatMail::class, fn (NotificationCandidatMail $m): bool => $m->sujetMessage === RelancesConfirmations::SUJET_EMAIL))->toHaveCount(1)
            ->and(CandidatureRelance::query()->where('cause', CandidatureRelance::CAUSE_CONFIRMATION)->count())->toBe(2)
            ->and($controle())->toHaveKey((string) $v->id);

        $a(18);
        $relancer();
        expect($relances())->toHaveCount(1);

        $a(24);
        $relancer();
        expect($relances())->toHaveCount(2);

        ($this->confirmer)($v);
        $a(31);
        $relancer();
        expect($relances())->toHaveCount(2)->and($controle())->toBe([]);
    });

    it('écrit une relance ASCII d\'un segment, sans téléphone ni adresse', function (): void {
        expect(RelancesConfirmations::TEXTE_SMS)->toBe('PSSFP : votre versement n\'est pas encore confirme. Presentez votre recu bancaire original a la scolarite pour le faire valider.')
            ->and(preg_match('/[^\x20-\x7E]/', RelancesConfirmations::TEXTE_SMS))->toBe(0)
            ->and(mb_strlen(RelancesConfirmations::TEXTE_SMS))->toBeLessThanOrEqual(160);
    });
});

describe('reçu', function (): void {
    it('porte la mention de vérification quand la confirmation précède l\'émission, figée sur le reçu', function (): void {
        $v = ($this->encaisser)(185000);
        $premier = RecuVersement::query()->sole();
        expect($premier->verifie_le)->toBeNull();

        ($this->confirmer)($v);
        $recus = app(RecusVersements::class);
        $recus->annuler($premier, 'Erreur sur le payeur', $this->financier);
        $nouveau = $recus->emettre($v->fresh(), $this->financier);

        $html = view('pdf.recu-versement', ['recu' => $nouveau->loadMissing('annulation'), 'lettres' => $recus->enLettres($nouveau->montant)])->render();
        expect($nouveau->verifie_par_nom)->toBe('Agent scolarite_agent')
            ->and($nouveau->verifie_le->toDateString())->toBe('2026-11-10')
            ->and($html)->toContain('Versement vérifié par la scolarité : Agent scolarite_agent, le 10/11/2026')
            ->and(view('pdf.recu-versement', ['recu' => $premier->fresh()->loadMissing('annulation'), 'lettres' => 'x'])->render())->not->toContain('Versement vérifié');
    });
});

describe('reprise', function (): void {
    it('reprend les versements existants comme confirmés par leur auteur, sans changer l\'accès de personne', function (): void {
        // Avant la reprise : des versements sans confirmation, tels qu'enregistrés avant ce changement.
        $soldeAwa = Versement::factory()->nonConfirme()->create(['inscription_annuelle_id' => ($this->inscription)($this->awa)->id, 'montant' => 185000,
            'saisi_par' => $this->agent->id, 'justificatif_path' => 'scolarite/justificatifs/01JREPRISE000000000000000A.pdf']);
        Versement::factory()->nonConfirme()->create(['inscription_annuelle_id' => ($this->inscription)($this->bruno)->id, 'montant' => 100000]);
        $annule = Versement::factory()->nonConfirme()->create(['inscription_annuelle_id' => ($this->inscription)($this->bruno)->id, 'montant' => 85000]);
        annulerVersement($annule);
        // Règle d'avant : tout l'argent imputé compte.
        $avant = collect([$this->awa, $this->bruno])->mapWithKeys(fn (Candidature $c): array => [$c->id => etatAvecToutLArgent($c)]);

        $reprises = app(RepriseConfirmations::class)->reprendre();

        $ligne = ConfirmationVersement::query()->where('versement_id', $soldeAwa->id)->sole();
        expect($reprises)->toBe(2)
            ->and(app(RepriseConfirmations::class)->reprendre())->toBe(0)
            ->and($ligne->reprise)->toBeTrue()
            ->and($ligne->confirme_par)->toBe($this->agent->id)
            ->and($ligne->confirme_at->equalTo($soldeAwa->created_at))->toBeTrue()
            ->and($ligne->justificatif_path)->toBe('scolarite/justificatifs/01JREPRISE000000000000000A.pdf')
            ->and(ConfirmationVersement::query()->where('versement_id', $annule->id)->exists())->toBeFalse()
            ->and(collect([$this->awa, $this->bruno])->mapWithKeys(fn (Candidature $c): array => [$c->id => ($this->etat)($c)])->all())->toBe($avant->all())
            ->and($avant[$this->awa->id])->toBe(AccesEcampus::DISPONIBLE);
    });
});

describe('droits', function (): void {
    it('donne la confirmation à la scolarité et aux administrateurs, pas au service financier, par migration additive', function (): void {
        expect($this->agent->can(ConfirmationsVersements::PERMISSION))->toBeTrue()
            ->and($this->responsable->can(ConfirmationsVersements::PERMISSION))->toBeTrue()
            ->and($this->financier->can(ConfirmationsVersements::PERMISSION))->toBeFalse();

        foreach (['scolarite_agent', 'scolarite_responsable', 'admin', 'super_admin'] as $r) {
            Role::findByName($r, 'web')->revokePermissionTo(ConfirmationsVersements::PERMISSION);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $migration = require database_path('migrations/'.MIGRATION_CONFIRMER);
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $droits = fn (string $r) => Role::findByName($r, 'web')->permissions()->pluck('name');
        foreach (['scolarite_agent', 'scolarite_responsable', 'admin', 'super_admin'] as $r) {
            expect($droits($r))->toContain(ConfirmationsVersements::PERMISSION);
        }
        expect($droits('service_financier'))->not->toContain(ConfirmationsVersements::PERMISSION);
    });

    it('rend à la scolarité la création d\'une inscription manquante', function (): void {
        $auditeur = $this->bruno->fresh()->auditeur;
        $auditeur->inscriptions()->delete();

        expect(app(OuvertureAuditeur::class)->creerInscription(Auditeur::query()->findOrFail($auditeur->id), $this->agent))->not->toBeNull();
    });
});
