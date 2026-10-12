<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Services\ResultatsSelection\ResultatsSelectionService;
use App\Services\ResultatsSelection\SeanceJury;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;

uses()->group('resultats-selection');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class]);

    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027', 'en' => 'Academic year 2026-2027'],
        'promotion_numero' => 14,
    ]);

    $this->retenu = fn (array $attributs = []) => Candidature::factory()
        ->forCampagne($this->campagne)
        ->submitted()
        ->create([
            'specialite' => 'Audit et contrôle des Finances Publiques',
            'depot_physique_at' => now()->subDay(),
            ...$attributs,
        ]);

    $this->service = app(ResultatsSelectionService::class);
});

it('retient les dossiers soumis en ligne et déposés physiquement, et eux seuls', function (): void {
    ($this->retenu)(['nom' => 'RETENU']);

    // Soumis en ligne mais dossier papier jamais présenté.
    Candidature::factory()->forCampagne($this->campagne)->submitted()->create(['nom' => 'SANSDEPOT']);
    // Brouillon jamais soumis.
    Candidature::factory()->forCampagne($this->campagne)->create(['nom' => 'BROUILLON']);
    // Retiré administrativement, refusé, supprimé : jamais retenus.
    ($this->retenu)(['nom' => 'RETIRE', 'withdrawn_at' => now()]);
    ($this->retenu)(['nom' => 'REFUSE', 'statut' => Candidature::STATUT_REFUSE]);
    ($this->retenu)(['nom' => 'SUPPRIME'])->delete();
    // Autre campagne.
    Candidature::factory()->submitted()->create(['nom' => 'AILLEURS', 'depot_physique_at' => now()]);

    $resultats = $this->service->construire($this->campagne, SeanceJury::vide());

    expect(array_map(fn ($c) => $c->nom, $resultats->nationaux))->toBe(['RETENU'])
        ->and($resultats->etrangers)->toBe([])
        ->and($resultats->totalRetenus())->toBe(1)
        // Total en ligne : tous les dossiers soumis de la campagne (hors supprimés).
        ->and($resultats->totalEnLigne)->toBe(4);
});

it('sépare nationaux et étrangers, triés par ordre alphabétique sans tenir compte des accents', function (): void {
    ($this->retenu)(['nom' => 'Zang', 'prenom' => 'Paul', 'type_etude' => 'distanciel']);
    ($this->retenu)(['nom' => 'Éboa', 'prenom' => 'Marie', 'type_etude' => 'presentiel']);
    ($this->retenu)(['nom' => 'Abena', 'prenom' => 'Joël']);
    ($this->retenu)(['nom' => 'Mahamat', 'prenom' => 'Ali', 'nationalite' => 'TD', 'type_etude' => 'distanciel']);
    ($this->retenu)(['nom' => 'Lema', 'prenom' => 'Jules', 'nationalite' => 'BJ']);

    $resultats = $this->service->construire($this->campagne, SeanceJury::vide());

    expect(array_map(fn ($c) => $c->nomComplet(), $resultats->nationaux))
        ->toBe(['ABENA JOËL', 'ÉBOA MARIE', 'ZANG PAUL'])
        ->and(array_map(fn ($c) => $c->mode, $resultats->nationaux))->toBe(['P', 'P', 'D'])
        ->and(array_map(fn ($c) => [$c->nomComplet(), $c->nationalite], $resultats->etrangers))
        ->toBe([['LEMA JULES', 'BÉNIN'], ['MAHAMAT ALI', 'TCHAD']]);
});

it('retire les caractères de contrôle des noms, interdits dans le XML Word', function (): void {
    ($this->retenu)(['nom' => "Mbarga\x01\u{200B}", 'prenom' => "Luc\tRoger"]);

    $resultats = $this->service->construire($this->campagne, SeanceJury::vide());

    expect($resultats->nationaux[0]->nomComplet())->toBe('MBARGA LUC ROGER');
});

it('calcule les trois tableaux de répartition du PV', function (): void {
    ($this->retenu)(['genre' => 'M', 'type_etude' => 'presentiel']);
    ($this->retenu)(['genre' => 'F', 'type_etude' => 'distanciel']);
    ($this->retenu)(['genre' => 'F', 'type_etude' => 'distanciel', 'nationalite' => 'TD',
        'specialite' => 'Fiscalité, Finance et Comptabilité Publique']);
    ($this->retenu)(['genre' => 'M', 'type_etude' => 'distanciel',
        'specialite' => 'Économie Publique et Gestion Publique']);

    $r = $this->service->construire($this->campagne, SeanceJury::vide());

    $audit = 'Audit et contrôle des Finances Publiques';
    $fiscalite = 'Fiscalité, Finance et Comptabilité Publique';

    // Lignes : les cinq spécialités dans l'ordre des PV, même vides.
    expect(array_keys($r->parPays->lignes))->toHaveCount(5)
        ->and(array_keys($r->parPays->lignes)[0])->toBe('Économie Publique et Gestion Publique')
        // Colonnes pays : Cameroun d'abord, puis les autres nationalités présentes.
        ->and($r->parPays->colonnes)->toBe(['CM' => 'CAMEROUN', 'TD' => 'TCHAD'])
        ->and($r->parPays->valeur($audit, 'CM'))->toBe(2)
        ->and($r->parPays->valeur($fiscalite, 'TD'))->toBe(1)
        ->and($r->parPays->totalLigne($audit))->toBe(2)
        ->and($r->parPays->totalColonne('CM'))->toBe(3)
        ->and($r->parPays->total())->toBe(4)
        ->and($r->parGenre->totalColonne('M'))->toBe(2)
        ->and($r->parGenre->totalColonne('F'))->toBe(2)
        ->and($r->parGenre->pourcentage('F'))->toBe('50 %')
        ->and($r->parMode->totalColonne('presentiel'))->toBe(1)
        ->and($r->parMode->totalColonne('distanciel'))->toBe(3)
        ->and($r->parMode->pourcentage('distanciel'))->toBe('75 %');
});

it('ajoute les colonnes Autre et Non précisé seulement si un dossier en relève', function (): void {
    ($this->retenu)(['genre' => 'autre', 'type_etude' => 'presentiel']);
    ($this->retenu)(['genre' => null, 'type_etude' => null, 'nationalite' => null]);

    $r = $this->service->construire($this->campagne, SeanceJury::vide());

    expect($r->parGenre->colonnes)->toBe(['M' => 'HOMMES', 'F' => 'FEMMES', 'autre' => 'AUTRE', '—' => 'NON PRÉCISÉ'])
        ->and($r->parMode->colonnes)->toHaveKey('—')
        ->and($r->parPays->colonnes)->toBe(['CM' => 'CAMEROUN', '—' => 'NON PRÉCISÉE'])
        ->and($r->etrangers[0]->nationalite)->toBe('NON PRÉCISÉE')
        ->and($r->etrangers[0]->mode)->toBe('—');
});

it('produit des tableaux vides mais complets pour une campagne sans retenu', function (): void {
    $r = $this->service->construire($this->campagne, SeanceJury::vide());

    expect($r->totalRetenus())->toBe(0)
        ->and($r->parPays->lignes)->toHaveCount(5)
        ->and($r->parGenre->pourcentage('M'))->toBe('0 %');
});

it('garde toutes les spécialités configurées même absentes de l\'ordre des PV', function (): void {
    config()->set('resultats_selection.ordre_specialites', ['audit-controle-finances-publiques', 'cle-inexistante']);

    $r = $this->service->construire($this->campagne, SeanceJury::vide());

    expect(array_keys($r->parPays->lignes))->toHaveCount(5)
        ->and(array_keys($r->parPays->lignes)[0])->toBe('Audit et contrôle des Finances Publiques');
});

it('regroupe une spécialité hors liste sur une ligne dédiée plutôt que de la perdre', function (): void {
    ($this->retenu)(['specialite' => 'Finances Publiques']);

    $r = $this->service->construire($this->campagne, SeanceJury::vide());

    expect($r->parPays->lignes)->toHaveKey('Autre spécialité')
        ->and($r->parPays->total())->toBe(1);
});

it('déduit la promotion et l\'année académique de la campagne', function (): void {
    $r = $this->service->construire($this->campagne, SeanceJury::vide());

    expect($r->promotionOrdinal())->toBe('QUATORZIEME')
        ->and($r->promotionAbregee())->toBe('14e')
        ->and($r->anneeAcademique)->toBe('2026-2027')
        ->and($r->anneeAcademiqueSlash())->toBe('2026/2027');
});

it('rédige le préambule du PV à partir de la séance saisie', function (): void {
    $seance = SeanceJury::depuisFormulaire([
        'date_deliberation' => '2026-10-01',
        'heure_deliberation' => '10 heures',
        'note_service_reference' => '26-801/UYII/FSEG-D',
        'note_service_date' => '2026-09-22',
        'numero' => '0123',
    ]);

    expect($seance->dateEnLettres())->toBe('L’an deux-mille-vingt-six et le 1er du mois d’octobre, à 10 heures')
        ->and($seance->noteService())->toBe('n°26-801/UYII/FSEG-D du 22 septembre 2026')
        ->and($seance->referencePv())->toBe('N°26/0123/PV/MINFI/PSSFP/PCS/USI/UPAAS/AT')
        ->and(SeanceJury::vide()->dateEnLettres())->toContain('……');
});
