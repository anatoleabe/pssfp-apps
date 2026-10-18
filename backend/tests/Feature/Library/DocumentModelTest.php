<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\MotCle;
use Illuminate\Database\QueryException;

uses()->group('module-3', 'library');

it('crée un document publié avec auteurs ordonnés et mots-clés', function (): void {
    $doc = Document::factory()->published()->create();
    $doc->authors()->createMany([
        ['author_name' => 'Auteur Deux', 'order' => 2],
        ['author_name' => 'Auteur Un', 'order' => 1],
    ]);
    $doc->motsCles()->attach(MotCle::factory()->create());

    $fresh = $doc->fresh();
    expect($fresh->authors->pluck('author_name')->all())->toBe(['Auteur Un', 'Auteur Deux'])
        ->and($fresh->motsCles)->toHaveCount(1)
        ->and($fresh->uuid)->not->toBeEmpty();
});

it('refuse un type inconnu', function (): void {
    Document::factory()->create(['type' => 'roman']);
})->throws(QueryException::class);

it('refuse deux documents issus du même identifiant Joomla', function (): void {
    Document::factory()->create(['legacy_source' => 'docman', 'legacy_id' => 42]);
    Document::factory()->create(['legacy_source' => 'docman', 'legacy_id' => 42]);
})->throws(QueryException::class);

it('dérive le mode d\'accès affiché', function (array $attrs, string $mode): void {
    expect(Document::factory()->make($attrs)->access_mode)->toBe($mode);
})->with([
    'public' => [['access_level' => 'public', 'price_xaf' => null], 'libre'],
    'réservé' => [['access_level' => 'auditor', 'price_xaf' => null], 'reserve'],
    'payant prime sur réservé' => [['access_level' => 'auditor', 'price_xaf' => 2000], 'payant'],
]);

it('ne retient que les documents publiés à date passée', function (): void {
    Document::factory()->published()->create();
    Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    Document::factory()->create(['status' => Document::STATUS_PUBLISHED, 'published_at' => now()->addDay()]);

    expect(Document::query()->published()->count())->toBe(1);
});

it('refuse en base un lien externe combiné à un fichier, un prix ou un accès réservé', function (array $attrs): void {
    Document::factory()->create(['external_url' => 'https://exemple.org/ouvrage', ...$attrs]);
})->with([
    'fichier' => [['file_path' => 'documents/x.pdf']],
    'prix' => [['price_xaf' => 2000]],
    'réservé' => [['access_level' => 'auditor']],
])->throws(QueryException::class);

it('accepte un lien externe seul', function (): void {
    expect(Document::factory()->external()->create()->exists)->toBeTrue();
});
