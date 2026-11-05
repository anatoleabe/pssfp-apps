<?php

declare(strict_types=1);

use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Services\DocumentsAuditeurs\ParametresLettres;

uses()->group('documents-auditeurs', 'scolarite');

beforeEach(function (): void {
    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'],
        'parametres_lettres' => ['frais_inscription_montant' => 150000, 'frais_tranche1_montant' => 450000, 'rentree_heure' => '9 heures'],
    ]);
});

it('garde les valeurs de la campagne tant que l\'année n\'a pas de barème', function (): void {
    $p = ParametresLettres::pour($this->campagne);

    expect($p->entier('frais_inscription_montant'))->toBe(150000)
        ->and($p->entier('frais_tranche1_montant'))->toBe(450000);
});

it('lit montants et échéances dans le barème M1 de l\'année, sans toucher au reste', function (): void {
    BaremeScolarite::factory()->create([
        'annee_academique' => '2026-2027', 'niveau' => 'M1',
        'inscription_echeance' => 'Avant la rentrée', 'tranche1_echeance' => '2026-12-15', 'tranche2_echeance' => '2027-03-15',
    ]);
    BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);

    $p = ParametresLettres::pour($this->campagne);

    expect($p->entier('frais_inscription_montant'))->toBe(185000)
        ->and($p->entier('frais_tranche1_montant'))->toBe(500000)
        ->and($p->texte('frais_inscription_echeance'))->toBe('Avant la rentrée')
        ->and($p->date('frais_tranche1_date'))->toBe('15 décembre 2026')
        ->and($p->totalFrais())->toBe("1\u{00A0}185\u{00A0}000")
        ->and($p->texte('rentree_heure'))->toBe('9 heures')
        ->and(ParametresLettres::pour($this->campagne, avecBareme: false)->entier('frais_inscription_montant'))->toBe(150000);
});
