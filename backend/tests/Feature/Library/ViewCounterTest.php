<?php

declare(strict_types=1);

use App\Models\Document;
use Illuminate\Support\Facades\Cache;

uses()->group('module-3', 'library');

beforeEach(fn () => Cache::flush());

it('compte une consultation et renvoie 204', function (): void {
    $doc = Document::factory()->published()->create();

    $this->postJson("/v1/library/documents/{$doc->uuid}/view")->assertNoContent();

    expect($doc->fresh()->views_count)->toBe(1);
});

it('ne compte qu\'une consultation par IP et par heure', function (): void {
    $doc = Document::factory()->published()->create();

    $this->postJson("/v1/library/documents/{$doc->uuid}/view")->assertNoContent();
    $this->postJson("/v1/library/documents/{$doc->uuid}/view")->assertNoContent();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->postJson("/v1/library/documents/{$doc->uuid}/view")->assertNoContent();

    expect($doc->fresh()->views_count)->toBe(2);
});

it('renvoie 404 pour un document inconnu, non publié ou un uuid invalide', function (): void {
    $brouillon = Document::factory()->create();

    $this->postJson("/v1/library/documents/{$brouillon->uuid}/view")->assertNotFound();
    $this->postJson('/v1/library/documents/7f1c0e9a-0000-4000-8000-000000000000/view')->assertNotFound();
    $this->postJson('/v1/library/documents/pas-un-uuid/view')->assertNotFound();
});

it('n\'incrémente plus les consultations à la lecture de la fiche', function (): void {
    $doc = Document::factory()->published()->create(['slug' => 'fiche-lue']);

    $this->getJson('/v1/library/documents/fiche-lue')->assertOk();

    expect($doc->fresh()->views_count)->toBe(0);
});
