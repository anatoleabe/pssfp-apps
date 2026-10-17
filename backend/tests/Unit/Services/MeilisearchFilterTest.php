<?php

declare(strict_types=1);

use App\Services\Library\Search\MeilisearchDocumentSearch;

it('traduit les filtres de l\'API en expressions Meilisearch', function (): void {
    expect(MeilisearchDocumentSearch::filterExpression([
        'type' => 'these',
        'acces' => 'payant',
        'annee_min' => 2015,
        'annee_max' => 2020,
        'mot_cle' => 'tva',
        'specialite' => 'audit"; DROP',
    ]))->toBe([
        'type = "these"',
        'access_mode = "payant"',
        'year >= 2015',
        'year <= 2020',
        'mots_cles = "tva"',
        'specialite = "audit\"; DROP"',
    ]);
});
