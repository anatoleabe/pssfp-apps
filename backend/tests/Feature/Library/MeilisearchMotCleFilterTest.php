<?php

declare(strict_types=1);

use App\Models\MotCle;
use App\Services\Library\Search\MeilisearchDocumentSearch;

it('étend le filtre mot_cle aux sous-thèmes', function (): void {
    $parent = MotCle::factory()->create(['slug' => 'institutions']);
    $child = MotCle::factory()->create(['slug' => 'cour-supreme', 'parent_id' => $parent->id]);
    MotCle::factory()->create(['slug' => 'grand-enfant', 'parent_id' => $child->id]);
    MotCle::factory()->create(['slug' => 'autre']);

    expect(MeilisearchDocumentSearch::filterExpression(['mot_cle' => 'institutions']))
        ->toBe(['mots_cles IN ["institutions", "cour-supreme", "grand-enfant"]'])
        ->and(MeilisearchDocumentSearch::filterExpression(['mot_cle' => 'cour-supreme']))
        ->toBe(['mots_cles IN ["cour-supreme", "grand-enfant"]']);
});

it('conserve une égalité exacte pour un slug de mot-clé inconnu', function (): void {
    expect(MeilisearchDocumentSearch::filterExpression(['mot_cle' => 'inconnu"x']))
        ->toBe(['mots_cles = "inconnu\\"x"']);
});
