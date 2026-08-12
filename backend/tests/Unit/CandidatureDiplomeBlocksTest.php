<?php

declare(strict_types=1);

use App\Support\CandidatureDiplomeBlocks;

uses()->group('candidatures', 'diplome-requis');

it('ne garde que les clés attendues', function (): void {
    $rows = [
        ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019, 'statut' => 'admin'],
    ];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toBe([['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019]]);
});

it('coupe les espaces et convertit les chaînes vides en null', function (): void {
    $rows = [['intitule' => '  DESS  ', 'etablissement' => '   ', 'annee' => '2019']];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toBe([['intitule' => 'DESS', 'etablissement' => null, 'annee' => 2019]]);
});

it('supprime les lignes entièrement vides', function (): void {
    $rows = [
        ['intitule' => '', 'etablissement' => '', 'annee' => ''],
        ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019],
    ];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toHaveCount(1);
});

it('plafonne à dix lignes', function (): void {
    $rows = array_fill(0, 15, ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019]);

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toHaveCount(CandidatureDiplomeBlocks::MAX_ROWS);
});

it('renvoie un tableau vide pour une valeur non tableau', function (): void {
    expect(CandidatureDiplomeBlocks::normalize('nope', CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))->toBe([])
        ->and(CandidatureDiplomeBlocks::normalize(null, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))->toBe([]);
});

it('rejette une année non numérique', function (): void {
    $rows = [['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 'mille']];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toBe([['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => null]]);
});

it('normalise aussi les formations professionnelles', function (): void {
    $rows = [['centre' => 'ISMP', 'qualification' => 'Certificat', 'annee' => 2021, 'x' => 1]];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::FORMATION_PRO_KEYS))
        ->toBe([['centre' => 'ISMP', 'qualification' => 'Certificat', 'annee' => 2021]]);
});

it('détecte une ligne incomplète', function (): void {
    $complete = ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019];
    $incomplete = ['intitule' => 'DESS', 'etablissement' => null, 'annee' => 2019];

    expect(CandidatureDiplomeBlocks::isRowComplete($complete, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))->toBeTrue()
        ->and(CandidatureDiplomeBlocks::isRowComplete($incomplete, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))->toBeFalse();
});
