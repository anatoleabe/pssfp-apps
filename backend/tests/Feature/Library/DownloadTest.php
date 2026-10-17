<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentDownload;
use Illuminate\Support\Facades\Storage;

uses()->group('module-3', 'library');

beforeEach(function (): void {
    Storage::fake('minio_documents');
    Storage::disk('minio_documents')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiration): string => "https://signe.test/{$path}?exp={$expiration->getTimestamp()}"
    );
});

it('redirige vers une URL signée de 30 minutes et journalise', function (): void {
    $this->travelTo(now()->startOfMinute());
    $doc = Document::factory()->published()->withFile()->create();

    $this->get("/v1/library/documents/{$doc->uuid}/download")
        ->assertRedirect("https://signe.test/{$doc->file_path}?exp=".now()->addMinutes(30)->getTimestamp());

    expect(DocumentDownload::query()->where('document_id', $doc->id)->count())->toBe(1)
        ->and($doc->fresh()->downloads_count)->toBe(1);
});

it('refuse un document réservé ou payant avec la raison', function (Closure $make, string $reason): void {
    $doc = $make();

    $this->getJson("/v1/library/documents/{$doc->uuid}/download")
        ->assertForbidden()
        ->assertJsonPath('reason', $reason);

    expect(DocumentDownload::count())->toBe(0);
})->with([
    'réservé' => [fn () => Document::factory()->published()->withFile()->reserved()->create(), 'reserved'],
    'payant' => [fn () => Document::factory()->published()->withFile()->paid()->create(), 'paid'],
]);

it('redirige vers le lien externe', function (): void {
    $doc = Document::factory()->published()->external('https://exemple.org/livre')->create();

    $this->get("/v1/library/documents/{$doc->uuid}/download")->assertRedirect('https://exemple.org/livre');
});

it('renvoie 404 pour un brouillon, un uuid inconnu ou un document sans fichier', function (): void {
    $brouillon = Document::factory()->withFile()->create();
    $sansFichier = Document::factory()->published()->create();

    $this->getJson("/v1/library/documents/{$brouillon->uuid}/download")->assertNotFound();
    $this->getJson("/v1/library/documents/{$sansFichier->uuid}/download")->assertNotFound();
    $this->getJson('/v1/library/documents/7f1c0e9a-0000-4000-8000-000000000000/download')->assertNotFound();
});

it('limite les téléchargements à 30 par minute et par client', function (): void {
    $doc = Document::factory()->published()->withFile()->create();

    foreach (range(1, 30) as $i) {
        $this->get("/v1/library/documents/{$doc->uuid}/download")->assertRedirect();
    }

    $this->getJson("/v1/library/documents/{$doc->uuid}/download")->assertStatus(429);
});

it('renvoie 404 pour un lien externe dont le schéma n\'est pas http(s)', function (string $url): void {
    $doc = Document::factory()->published()->external($url)->create();

    $this->getJson("/v1/library/documents/{$doc->uuid}/download")->assertNotFound();
})->with(['javascript:alert(1)', 'ftp://exemple.org/livre.pdf', 'data:text/html,bonjour']);
