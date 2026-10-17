<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Models\MotCle;
use App\Models\User;
use App\Services\Library\Files\CoverRenderer;
use App\Services\Library\Search\MeilisearchDocumentSearch;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;

uses()->group('module-3', 'library', 'meilisearch');

function meili(): Client
{
    return new Client(config('scout.meilisearch.host'), config('scout.meilisearch.key'));
}

/** Attend la fin de toutes les tâches en cours (sans pause fixe). */
function meiliIdle(): void
{
    $deadline = microtime(true) + 15;
    do {
        $pending = meili()->getTasks((new TasksQuery)->setStatuses(['enqueued', 'processing']))->getResults();
    } while ($pending !== [] && microtime(true) < $deadline && usleep(50_000) === null);
}

beforeEach(function (): void {
    $host = config('scout.meilisearch.host');
    try {
        $up = Http::timeout(1)->get(rtrim((string) $host, '/').'/health')->successful();
    } catch (Throwable) {
        $up = false;
    }
    if (! $up) {
        $this->markTestSkipped('Meilisearch injoignable.');
    }

    // Clé : MEILISEARCH_KEY du processus, sinon celle du .env de dev (jamais affichée).
    $key = config('scout.meilisearch.key');
    if (! $key && is_file(base_path('.env'))) {
        $key = Dotenv\Dotenv::parse((string) file_get_contents(base_path('.env')))['MEILISEARCH_KEY'] ?? null;
    }

    config(['scout.meilisearch.key' => $key, 'scout.driver' => 'meilisearch', 'scout.prefix' => 'test_', 'scout.queue' => false]);
    // Garde : ne jamais toucher l'index réel de dev/prod.
    $this->testIndex = (new Document)->searchableAs();
    expect($this->testIndex)->toStartWith('test_');

    try {
        meili()->deleteIndex($this->testIndex);
        meiliIdle();
    } catch (Throwable) {
        // index absent
    }
    Artisan::call('scout:sync-index-settings');
    meiliIdle();
});

afterEach(function (): void {
    if (! isset($this->testIndex) || ! str_starts_with($this->testIndex, 'test_')) {
        return;
    }

    try {
        meili()->deleteIndex($this->testIndex);
        meiliIdle();
    } catch (Throwable) {
        // rien à nettoyer
    }
});

it('trouve malgré une faute de frappe, avec facettes, ordre et surlignage', function (): void {
    $a = Document::factory()->published()->create(['title' => ['fr' => 'Décentralisation fiscale'], 'type' => 'memoire']);
    Document::factory()->published()->paid()->create(['title' => ['fr' => 'Gestion des marchés publics'], 'type' => 'ouvrage_numerique']);
    Document::factory()->published()->create(['title' => ['fr' => 'Décentralisation et finances locales'], 'type' => 'memoire']);
    meiliIdle();

    $result = (new MeilisearchDocumentSearch)->search('decentralisaton', [], 1, 20);

    expect($result->total)->toBe(2)
        ->and($result->documents)->toHaveCount(2)
        ->and($result->documents->pluck('id')->all())->toContain($a->id)
        ->and($result->facets['type']['memoire'])->toBe(2)
        ->and($result->facets['access_mode'])->toHaveKey('libre')
        ->and(implode('', $result->highlights))->toContain('<mark>');

    $paid = (new MeilisearchDocumentSearch)->search('marches', [], 1, 20);
    expect($paid->facets['access_mode']['payant'])->toBe(1);
});

it('ne renvoie pas un document dépublié encore présent dans l\'index', function (): void {
    $doc = Document::factory()->published()->create(['title' => ['fr' => 'Audit obsolète']]);
    meiliIdle();
    Document::withoutSyncingToSearch(fn () => $doc->update(['status' => Document::STATUS_ARCHIVED]));

    $result = (new MeilisearchDocumentSearch)->search('audit', [], 1, 20);

    expect($result->documents)->toHaveCount(0);
});

it('n\'indexe pas un brouillon après le traitement de son fichier', function (): void {
    Storage::fake('minio_documents');
    Storage::fake('minio_media');
    Storage::disk('minio_documents')->put('documents/brouillon.pdf', '%PDF-1.4 brouillon');
    app()->instance(CoverRenderer::class, new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            return null;
        }
    });
    Document::factory()->published()->create(['title' => ['fr' => 'Fiscalité locale publiée']]);
    $draft = Document::withoutEvents(fn () => Document::factory()->create([
        'title' => ['fr' => 'Fiscalité locale en brouillon'],
        'file_path' => 'documents/brouillon.pdf',
    ]));

    (new ProcessDocumentFile($draft->id))->handle(app(CoverRenderer::class));
    meiliIdle();

    $result = (new MeilisearchDocumentSearch)->search('fiscalite', [], 1, 20);

    expect($result->total)->toBe(1)
        ->and($result->documents->pluck('id')->all())->not->toContain($draft->id)
        ->and($result->highlights)->not->toHaveKey($draft->uuid)
        ->and($result->highlights)->toHaveCount(1);
});

it('ne surligne que les documents renvoyés', function (): void {
    $doc = Document::factory()->published()->create(['title' => ['fr' => 'Contrôle budgétaire retiré']]);
    meiliIdle();
    Document::withoutSyncingToSearch(fn () => $doc->update(['status' => Document::STATUS_ARCHIVED]));

    $result = (new MeilisearchDocumentSearch)->search('budgetaire', [], 1, 20);

    expect($result->documents)->toHaveCount(0)
        ->and($result->highlights)->toBe([]);
});

it('réindexe un document publié avec ses mots-clés après l\'édition Filament', function (): void {
    Bus::fake([ProcessDocumentFile::class]);
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([RolePermissionSeeder::class]);
    $librarian = User::factory()->create();
    $librarian->assignRole('librarian');
    $motCle = MotCle::query()->create(['slug' => 'dette-publique', 'label' => ['fr' => 'Dette publique']]);
    $doc = Document::factory()->published()->create(['title' => ['fr' => 'Soutenabilité de la dette']]);
    meiliIdle();

    $this->actingAs($librarian);
    $this->livewire(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->fillForm(['motsCles' => [$motCle->id]])
        ->call('save')
        ->assertHasNoFormErrors();
    meiliIdle();

    $result = (new MeilisearchDocumentSearch)->search('dette', ['mot_cle' => 'dette-publique'], 1, 20);

    expect($result->documents->pluck('id')->all())->toBe([$doc->id]);
});
