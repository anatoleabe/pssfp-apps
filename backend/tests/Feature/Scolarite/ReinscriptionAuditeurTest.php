<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\AcmaDossier;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Deliberation;
use App\Models\InscriptionAnnuelle;
use App\Models\Parcours;
use App\Models\ResultatAnnuel;
use App\Models\User;
use App\Services\Acma\LienArchive;
use App\Services\Acma\Reprise\RepriseAcma;
use App\Services\Scolarite\ReinscriptionAuditeur;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Acma\TransitAcma;

uses()->group('scolarite');

/* Réinscription, depuis la fiche, d'un auditeur d'une promotion antérieure dans l'année en cours (17/11/2026). Jeu fictif. */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('scolarite.annee_en_cours', '2026-2027');
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->p14 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14, 'slug' => 'p14-2026', 'prefix_numero' => 'P14026-']);
    $this->p13 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13, 'slug' => 'p13-2025', 'prefix_numero' => 'P13025-']);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027']);
    BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    Parcours::query()->create(['code' => 'TC', 'libelle' => 'Tronc commun', 'niveau' => 'M1', 'actif' => true, 'ordre' => 1]);
    Parcours::query()->create(['code' => 'ACFP', 'libelle' => 'Audit et contrôle', 'niveau' => 'M2', 'specialite' => 'Audit et contrôle des finances publiques', 'actif' => true, 'ordre' => 2]);
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->auditeurDe = function (CampagneCandidature $campagne): Auditeur {
        $c = Candidature::factory()->forCampagne($campagne)->submitted()->create([
            'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'email' => Str::random(8).'@example.org',
        ]);

        return Auditeur::query()->create(['candidature_id' => $c->id]);
    };
    $this->ancien = ($this->auditeurDe)($this->p13);
    $this->reinscrire = fn (array $saisie, ?User $auteur = null): InscriptionAnnuelle => app(ReinscriptionAuditeur::class)
        ->reinscrire($this->ancien->fresh(), $saisie, $auteur ?? $this->agent);
    /** M1 de 2025-2026 sur la plateforme, avec la décision d'une délibération arrêtée (de l'année, ou d'un semestre). */
    $this->m1Delibere = function (string $decision, ?int $semestre = null): InscriptionAnnuelle {
        $m1 = BaremeScolarite::factory()->create(['annee_academique' => '2025-2026']);
        $inscription = InscriptionAnnuelle::query()->create([
            'auditeur_id' => $this->ancien->id, 'bareme_id' => $m1->id, 'annee_academique' => '2025-2026', 'niveau' => 'M1',
            'montant_inscription' => $m1->montant_inscription, 'montant_tranche1' => $m1->montant_tranche1, 'montant_tranche2' => $m1->montant_tranche2,
        ]);
        $d = Deliberation::query()->create(['annee_academique' => '2025-2026', 'niveau' => 'M1', 'session' => 'normale', 'semestre' => $semestre]);
        $d->forceFill(['statut' => Deliberation::ARRETEE, 'numero_pv' => 'PVD-2025-2026-000'.random_int(1, 9), 'pv_sha256' => str_repeat('a', 64), 'arretee_at' => now()])->save();
        ResultatAnnuel::query()->create(['deliberation_id' => $d->id, 'inscription_annuelle_id' => $inscription->id, 'credits_acquis' => $decision === 'AD' ? 60 : 40,
            'credits_annee' => 60, 'decision_calculee' => $decision, 'decision' => $decision]);

        return $inscription;
    };
});

it('réinscrit en M1 dans l\'année en cours un auditeur sans inscription d\'une promotion antérieure, qui reste dans sa promotion', function (): void {
    $inscription = ($this->reinscrire)(['niveau' => 'M1', 'parcours' => 'TC']);

    expect($inscription->annee_academique)->toBe('2026-2027')->and($inscription->niveau)->toBe('M1')
        ->and($inscription->parcours->code)->toBe('TC')
        ->and($this->ancien->fresh()->candidature->campagne_id)->toBe($this->p13->id)
        ->and($this->ancien->fresh()->inscriptionCourante->is($inscription))->toBeTrue();
    $journal = Activity::query()->where('event', ReinscriptionAuditeur::EVENEMENT)->sole();
    expect($journal->properties['annee'])->toBe('2026-2027')->and($journal->properties['niveau'])->toBe('M1')
        ->and($journal->properties['promotion'])->toBe(13)->and($journal->causer_id)->toBe($this->agent->id);
});

it('refuse le M2 à un M1 suivi mais ajourné, ou validé pour un seul semestre', function (string $decision, ?int $semestre): void {
    ($this->m1Delibere)($decision, $semestre);

    expect(ReinscriptionAuditeur::estAdmisEnM1($this->ancien))->toBeFalse()
        ->and(fn () => ($this->reinscrire)(['niveau' => 'M2', 'parcours' => 'ACFP']))->toThrow(InvalidArgumentException::class, 'Aucun M1 admis');
})->with([['AJ', null], ['AD', 1]]);

it('réinscrit en M2 un auditeur admis en M1 sur la plateforme, une année antérieure', function (): void {
    ($this->m1Delibere)('AD');

    $inscription = ($this->reinscrire)(['niveau' => 'M2', 'parcours' => 'ACFP']);

    expect($inscription->annee_academique)->toBe('2026-2027')->and($inscription->niveau)->toBe('M2')
        ->and($inscription->parcours->code)->toBe('ACFP')
        ->and(InscriptionAnnuelle::query()->where('auditeur_id', $this->ancien->id)->count())->toBe(2);
});

it('réinscrit en M2 un auditeur dont le M1 admis est dans l\'archive ACMA liée', function (): void {
    Storage::fake('local');
    TransitAcma::construire();
    $admin = ($this->compte)('admin');
    app(RepriseAcma::class)->executer($admin, null, true);
    LienArchive::poser(AcmaDossier::query()->where('matricule', '90EFP001')->firstOrFail(), $this->ancien, 'Même personne (essai)', $admin);

    expect(($this->reinscrire)(['niveau' => 'M2', 'parcours' => 'ACFP'])->niveau)->toBe('M2');
});

it('refuse le M2 sans M1 admis, sauf dérogation motivée avec sa pièce par le responsable', function (): void {
    expect(fn () => ($this->reinscrire)(['niveau' => 'M2', 'parcours' => 'ACFP']))
        ->toThrow(InvalidArgumentException::class, 'dérogation')
        ->and(fn () => ($this->reinscrire)(['niveau' => 'M2', 'parcours' => 'ACFP', 'derogation_m2' => true, 'derogation_piece' => 'Note 12/2026', 'motif' => 'Reprise validée par le directeur']))
        ->toThrow(InvalidArgumentException::class, 'droit « dérogation M2 »')
        ->and(fn () => ($this->reinscrire)(['niveau' => 'M2', 'parcours' => 'ACFP', 'derogation_m2' => true, 'derogation_piece' => '', 'motif' => 'Reprise validée par le directeur'], $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'référence de la pièce')
        ->and(fn () => ($this->reinscrire)(['niveau' => 'M2', 'parcours' => 'ACFP', 'derogation_m2' => true, 'derogation_piece' => 'Note 12/2026', 'motif' => 'court'], $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'motif');
    expect(InscriptionAnnuelle::query()->exists())->toBeFalse();

    ($this->reinscrire)(['niveau' => 'M2', 'parcours' => 'ACFP', 'derogation_m2' => true, 'derogation_piece' => 'Note 12/2026', 'motif' => 'Reprise validée par le directeur'], $this->responsable);
    $journal = Activity::query()->where('event', ReinscriptionAuditeur::EVENEMENT)->sole();
    expect($journal->properties['derogation_m2'])->toBeTrue()->and($journal->properties['derogation_piece'])->toBe('Note 12/2026');
});

it('refuse un auditeur déjà inscrit cette année, de la promotion en cours, ou au dossier retiré, un parcours hors niveau et un agent sans droit', function (): void {
    expect(fn () => ($this->reinscrire)(['niveau' => 'M1', 'parcours' => 'ACFP']))->toThrow(InvalidArgumentException::class, 'Parcours inconnu')
        ->and(fn () => ($this->reinscrire)(['niveau' => 'M1', 'parcours' => 'TC'], ($this->compte)('caisse')))->toThrow(HttpException::class)
        ->and(fn () => ($this->reinscrire)(['niveau' => 'M1', 'parcours' => 'TC'], ($this->compte)('service_financier')))->toThrow(HttpException::class);

    ($this->reinscrire)(['niveau' => 'M1', 'parcours' => 'TC']);
    expect(fn () => ($this->reinscrire)(['niveau' => 'M1', 'parcours' => 'TC']))->toThrow(InvalidArgumentException::class, 'promotion antérieure');

    $nouveau = ($this->auditeurDe)($this->p14);
    expect(fn () => app(ReinscriptionAuditeur::class)->reinscrire($nouveau, ['niveau' => 'M1', 'parcours' => 'TC'], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'promotion antérieure');

    $retire = ($this->auditeurDe)($this->p13);
    $retire->candidature->forceFill(['withdrawn_at' => now()])->save();
    expect(fn () => app(ReinscriptionAuditeur::class)->reinscrire($retire->fresh(), ['niveau' => 'M1', 'parcours' => 'TC'], $this->agent))
        ->toThrow(InvalidArgumentException::class, 'retiré');
    expect(InscriptionAnnuelle::query()->count())->toBe(1);
});

it('propose « Réinscrire » et non « Créer l\'inscription » sur la fiche d\'un ancien, et l\'inverse pour la promotion en cours', function (): void {
    $this->actingAs($this->agent);
    $this->livewire(ViewAuditeur::class, ['record' => $this->ancien->getRouteKey()])
        ->assertActionVisible('reinscrire')
        ->assertActionHidden('creerInscription')
        ->callAction('reinscrire', ['niveau' => 'M1', 'parcours' => 'TC'])
        ->assertHasNoActionErrors()
        ->assertActionHidden('reinscrire');
    expect($this->ancien->fresh()->inscriptionCourante?->annee_academique)->toBe('2026-2027');

    $nouveau = ($this->auditeurDe)($this->p14);
    $this->livewire(ViewAuditeur::class, ['record' => $nouveau->getRouteKey()])
        ->assertActionHidden('reinscrire')
        ->assertActionVisible('creerInscription');
});
