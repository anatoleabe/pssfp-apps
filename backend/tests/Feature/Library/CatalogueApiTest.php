<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\MotCle;

uses()->group('module-3', 'library');

it('compte les documents publiés par type et signale la présence de payants', function (): void {
    Document::factory()->published()->count(2)->create(['type' => 'memoire']);
    Document::factory()->published()->paid()->create(['type' => 'ouvrage_numerique']);
    Document::factory()->create(['type' => 'these']);

    $this->getJson('/v1/library/stats')
        ->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.by_type.memoire', 2)
        ->assertJsonPath('data.by_type.ouvrage_numerique', 1)
        ->assertJsonPath('data.paid_count', 1)
        ->assertJsonMissingPath('data.by_type.these');
});

it('liste les documents publiés, 20 par page, sans identifiant interne', function (): void {
    Document::factory()->published()->count(25)->create();
    Document::factory()->count(3)->create();

    $response = $this->getJson('/v1/library/documents')->assertOk();

    expect($response->json('data'))->toHaveCount(20)
        ->and($response->json('meta.total'))->toBe(25)
        ->and($response->json('data.0'))->not->toHaveKey('id')
        ->and($response->json('data.0'))->toHaveKeys(['uuid', 'slug', 'access_mode', 'cover_url']);
});

it('filtre par type, accès, mot-clé et plage d\'années', function (): void {
    $mc = MotCle::factory()->create(['slug' => 'fiscalite']);
    $cible = Document::factory()->published()->create(['type' => 'these', 'year' => 2020]);
    $cible->motsCles()->attach($mc);
    Document::factory()->published()->create(['type' => 'these', 'year' => 2010]);
    Document::factory()->published()->reserved()->create(['type' => 'these', 'year' => 2020]);
    Document::factory()->published()->create(['type' => 'memoire', 'year' => 2020]);

    $this->getJson('/v1/library/documents?type=these&acces=libre&mot_cle=fiscalite&annee_min=2015&annee_max=2025')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $cible->uuid);
});

it('trie par popularité', function (): void {
    Document::factory()->published()->create(['slug' => 'peu-lu', 'views_count' => 3]);
    Document::factory()->published()->create(['slug' => 'tres-lu', 'views_count' => 900]);

    $this->getJson('/v1/library/documents?tri=populaire')->assertJsonPath('data.0.slug', 'tres-lu');
});

it('rejette un tri inconnu', function (): void {
    $this->getJson('/v1/library/documents?tri=hasard')->assertUnprocessable();
});

it('affiche une fiche avec la décision d\'accès et compte la consultation', function (): void {
    $doc = Document::factory()->published()->withFile()->reserved()->create(['slug' => 'support-ue4']);
    $doc->authors()->create(['author_name' => 'Auteur Test', 'order' => 1]);
    Document::factory()->published()->create(['type' => $doc->type]);

    $this->getJson('/v1/library/documents/support-ue4')
        ->assertOk()
        ->assertJsonPath('data.access.allowed', false)
        ->assertJsonPath('data.access.reason', 'reserved')
        ->assertJsonPath('data.authors.0', 'Auteur Test')
        ->assertJsonCount(1, 'related');

    expect($doc->fresh()->views_count)->toBe(1);
});

it('renvoie 404 pour une fiche non publiée', function (): void {
    Document::factory()->create(['slug' => 'brouillon']);

    $this->getJson('/v1/library/documents/brouillon')->assertNotFound();
});

it('liste le thésaurus', function (): void {
    MotCle::factory()->create(['slug' => 'tva', 'label' => ['fr' => 'TVA']]);

    $this->getJson('/v1/library/mots-cles')->assertOk()->assertJsonPath('data.0.slug', 'tva');
});

it('résout une ancienne URL Joomla et compte l\'usage', function (): void {
    $r = LibraryRedirect::create(['from_path' => '/index.php/textes-et-lois/lois', 'to_path' => '/collections/texte_loi']);

    $this->getJson('/v1/library/redirects?path=/index.php/textes-et-lois/lois')
        ->assertOk()
        ->assertJsonPath('data.to_path', '/collections/texte_loi')
        ->assertJsonPath('data.status_code', 301);

    expect($r->fresh()->hits)->toBe(1);
    $this->getJson('/v1/library/redirects?path=/inconnu')->assertNotFound();
});
