<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Services\Scolarite\ExtraitCompte;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\Solvabilite;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Extrait de compte d'un auditeur et classement de solvabilité (17/11/2026) : échu impayé sur tout le parcours,
 * versements à confirmer comptés. Données fictives.
 */

uses()->group('scolarite');

/** Inscription en mémoire : barème 185 000 / 500 000 / 500 000, échéances données, imputé préchargé. */
function inscriptionEnMemoire(int $verse, ?string $echeance1 = null, ?string $echeance2 = null, int $confirme = -1): InscriptionAnnuelle
{
    $i = new InscriptionAnnuelle;
    $i->setRawAttributes([
        'montant_inscription' => 185000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000, 'remise_montant' => 0,
        'imputations_actives_sum_montant' => $verse, 'imputations_confirmees_sum_montant' => $confirme < 0 ? $verse : $confirme,
    ]);

    return $i->setRelation('bareme', new BaremeScolarite(['tranche1_echeance' => $echeance1, 'tranche2_echeance' => $echeance2]));
}

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
});

describe('classement', function (): void {
    it('est en règle sans rien d\'échu impayé : droits payés, tranches pas encore échues', function (): void {
        $c = Solvabilite::classer([inscriptionEnMemoire(185000, '2026-12-01', '2027-03-01')], 185000);

        expect($c['categorie'])->toBe(Solvabilite::EN_REGLE)->and($c['echu_impaye'])->toBe(0)->and($c['reste'])->toBe(1000000);
    });

    it('est partiellement insolvable avec un versement et une tranche échue impayée', function (): void {
        $c = Solvabilite::classer([inscriptionEnMemoire(300000, '2026-11-01', '2027-03-01')], 300000);

        expect($c['categorie'])->toBe(Solvabilite::PARTIELLE)->and($c['echu_impaye'])->toBe(385000);
    });

    it('est totalement insolvable sans aucun versement, les droits d\'inscription étant dus dès l\'inscription', function (): void {
        $c = Solvabilite::classer([inscriptionEnMemoire(0, '2026-12-01', '2027-03-01')], 0);

        expect($c['categorie'])->toBe(Solvabilite::TOTALE)->and($c['echu_impaye'])->toBe(185000);
    });

    it('compte les versements à confirmer comme payés', function (): void {
        $c = Solvabilite::classer([inscriptionEnMemoire(185000, '2026-12-01', null, confirme: 0)], 185000);

        expect($c['categorie'])->toBe(Solvabilite::EN_REGLE)->and($c['a_confirmer'])->toBe(185000);
    });

    it('juge tout le parcours : un arriéré d\'une année passée rend insolvable', function (): void {
        $c = Solvabilite::classer([inscriptionEnMemoire(1000000, '2025-12-01', '2026-03-01'), inscriptionEnMemoire(185000, '2026-12-01', '2027-03-01')], 1185000);

        expect($c['categorie'])->toBe(Solvabilite::PARTIELLE)->and($c['echu_impaye'])->toBe(185000)->and($c['reste'])->toBe(1185000);
    });
});

describe('extrait de compte', function (): void {
    beforeEach(function (): void {
        $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13]);
        $dossier = Candidature::factory()->forCampagne($campagne)->submitted()->create([
            'nom' => 'ESSAI', 'prenom' => 'Fictif', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+2376990'.random_int(10000, 99999),
            'email' => 'essai-'.Str::random(6).'@example.org',
        ]);
        $dossier->forceFill(['matricule' => '25EFP900'])->save();
        $this->auditeur = Auditeur::query()->create(['candidature_id' => $dossier->id]);
        foreach (['2025-2026' => ['2025-12-01', '2026-03-01'], '2026-2027' => ['2026-11-01', '2027-03-01']] as $annee => [$e1, $e2]) {
            $b = BaremeScolarite::factory()->create(['annee_academique' => $annee, 'tranche1_echeance' => $e1, 'tranche2_echeance' => $e2]);
            InscriptionAnnuelle::query()->create([
                'auditeur_id' => $this->auditeur->id, 'bareme_id' => $b->id, 'annee_academique' => $annee, 'niveau' => 'M1',
                'montant_inscription' => $b->montant_inscription, 'montant_tranche1' => $b->montant_tranche1, 'montant_tranche2' => $b->montant_tranche2,
            ]);
        }
        app(RegistreVersements::class)->encaisser($this->auditeur->fresh()->inscriptionCourante, [
            'montant' => 1300000, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => 'BOR-EXTRAIT', 'payeur' => 'auditeur',
        ], $this->financier);
    });

    it('détaille le dû par année, les versements et la synthèse sur le parcours', function (): void {
        $d = app(ExtraitCompte::class)->donnees($this->auditeur->fresh());

        expect(array_column($d['annees'], 'annee'))->toBe(['2025-2026', '2026-2027'])
            ->and($d['versements'])->toHaveCount(1)
            ->and($d['versements'][0])->toMatchArray(['reference' => 'BOR-EXTRAIT', 'montant' => 1300000])
            ->and($d['versements'][0]['recu'])->not->toBeNull()
            ->and($d['totaux'])->toMatchArray(['du' => 2370000, 'verse_valide' => 1300000, 'reste' => 1070000, 'a_confirmer' => 1300000])
            // 2025-2026 soldée (1 185 000), puis 115 000 sur les droits 2026-2027 : 70 000 de droits et la 1re tranche échue restent dus.
            ->and($d['totaux']['echu_impaye'])->toBe(570000)
            ->and($d['totaux']['categorie'])->toBe(Solvabilite::PARTIELLE);

        $html = view('pdf.scolarite.extrait-compte', $d)->render();
        expect($html)->toContain('Extrait de compte')->toContain('25EFP900')->toContain('BOR-EXTRAIT')
            ->toContain('PARTIELLEMENT INSOLVABLE')->toContain('Échu impayé')->toContain('1 070 000 FCFA');
    });

    it('s\'affiche pour l\'impression, se télécharge, et chaque édition est journalisée', function (): void {
        $url = route('filament.admin.auditeurs.extrait-compte', $this->auditeur);
        $this->actingAs($this->financier);

        $apercu = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($apercu->headers->get('Content-Disposition'))->toStartWith('inline;')->toContain('extrait-compte-25efp900.pdf')
            ->and($apercu->getContent())->toStartWith('%PDF');
        expect($this->get($url.'?telecharger=1')->headers->get('Content-Disposition'))->toStartWith('attachment;');
        expect(Activity::query()->where('event', ExtraitCompte::EVENEMENT)->count())->toBe(2);

        $this->livewire(ViewAuditeur::class, ['record' => $this->auditeur->getRouteKey()])
            ->assertActionVisible('extraitCompte')->assertActionHasUrl('extraitCompte', $url);
    });

    it('est refusé hors de l\'administration et aux rôles sans lecture des comptes', function (): void {
        $url = route('filament.admin.auditeurs.extrait-compte', $this->auditeur);

        $this->get($url)->assertRedirect();
        $this->actingAs(($this->compte)('editor'))->get($url)->assertForbidden();
        expect(Activity::query()->where('event', ExtraitCompte::EVENEMENT)->exists())->toBeFalse();
    });
});
