<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('applications');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);
    $this->campagne = CampagneCandidature::factory()->create(['slug' => 'p14-2026']);
});

function dossierTranche(CampagneCandidature $campagne, string $statut, array $attributs = []): Candidature
{
    return Candidature::factory()->forCampagne($campagne)->create(array_merge([
        'statut' => $statut,
        'submitted_at' => now()->subMonth(),
        'decided_at' => now()->subDay(),
    ], $attributs));
}

it('reporte motifs et commentaires depuis le journal, unitaires comme groupés', function (): void {
    $retenu = dossierTranche($this->campagne, Candidature::STATUT_ACCEPTE);
    $refuseSeul = dossierTranche($this->campagne, Candidature::STATUT_REFUSE);
    $refuseEnLot = dossierTranche($this->campagne, Candidature::STATUT_REFUSE);

    activity('candidatures')->performedOn($retenu)->event('candidature_accepted')
        ->withProperties(['internal_comment' => 'Très bon dossier.'])->log('Candidature acceptée');
    activity('candidatures')->performedOn($refuseSeul)->event('candidature_refused')
        ->withProperties(['motif' => 'Diplôme requis non fourni au dossier.'])->log('Candidature refusée');
    activity('candidatures')->event('candidature_refused_bulk')
        ->withProperties(['numeros' => [$refuseEnLot->numero_dossier], 'motif' => 'Places limitées pour cette promotion.'])
        ->log('Refus en bulk');

    $this->artisan('candidatures:reprendre-textes-decision', ['--appliquer' => true])->assertSuccessful();

    expect($retenu->fresh()->decision_commentaire)->toBe('Très bon dossier.')
        ->and($refuseSeul->fresh()->decision_motif)->toBe('Diplôme requis non fourni au dossier.')
        ->and($refuseEnLot->fresh()->decision_motif)->toBe('Places limitées pour cette promotion.');
});

it('n\'écrit rien en simulation et n\'écrase jamais un texte déjà posé', function (): void {
    $dejaRempli = dossierTranche($this->campagne, Candidature::STATUT_REFUSE, ['decision_motif' => 'Motif actuel']);
    $vide = dossierTranche($this->campagne, Candidature::STATUT_REFUSE);

    foreach ([$dejaRempli, $vide] as $dossier) {
        activity('candidatures')->performedOn($dossier)->event('candidature_refused')
            ->withProperties(['motif' => 'Motif du journal'])->log('Candidature refusée');
    }

    $this->artisan('candidatures:reprendre-textes-decision')->assertSuccessful();
    expect($vide->fresh()->decision_motif)->toBeNull();

    $this->artisan('candidatures:reprendre-textes-decision', ['--appliquer' => true])->assertSuccessful();
    expect($vide->fresh()->decision_motif)->toBe('Motif du journal')
        ->and($dejaRempli->fresh()->decision_motif)->toBe('Motif actuel');
});

it('ne prête jamais à un retenu le motif d\'un refus antérieur annulé', function (): void {
    $dossier = dossierTranche($this->campagne, Candidature::STATUT_ACCEPTE);

    activity('candidatures')->performedOn($dossier)->event('candidature_refused')
        ->withProperties(['motif' => 'Motif d\'un refus annulé ensuite.'])->log('Candidature refusée');
    activity('candidatures')->performedOn($dossier)->event('candidature_status_changed')
        ->withProperties(['from' => 'refuse', 'to' => 'candidat'])->log('Retour en examen');
    activity('candidatures')->event('candidature_accepted_bulk')
        ->withProperties(['numeros' => [$dossier->numero_dossier]])->log('Acceptation en bulk');

    $this->artisan('candidatures:reprendre-textes-decision', ['--appliquer' => true])->assertSuccessful();

    expect($dossier->fresh()->decision_commentaire)->toBeNull()
        ->and($dossier->fresh()->decision_motif)->toBeNull();
});

it('retient le motif du refus groupé le plus récent plutôt qu\'un refus unitaire plus ancien', function (): void {
    $dossier = dossierTranche($this->campagne, Candidature::STATUT_REFUSE);

    activity('candidatures')->performedOn($dossier)->event('candidature_refused')
        ->withProperties(['motif' => 'Ancien motif unitaire.'])->log('Candidature refusée');
    activity('candidatures')->event('candidature_refused_bulk')
        ->withProperties(['numeros' => [$dossier->numero_dossier], 'motif' => 'Motif du lot, envoyé en dernier.'])
        ->log('Refus en bulk');

    $this->artisan('candidatures:reprendre-textes-decision', ['--appliquer' => true])->assertSuccessful();

    expect($dossier->fresh()->decision_motif)->toBe('Motif du lot, envoyé en dernier.');
});
