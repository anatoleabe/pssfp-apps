<?php

declare(strict_types=1);

use App\Models\Document;
use App\Services\Library\Search\MeilisearchDocumentSearch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;

uses()->group('module-3', 'library', 'meilisearch');

const TEST_INDEX = 'test_documents';

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
    try {
        meili()->deleteIndex(TEST_INDEX);
        meiliIdle();
    } catch (Throwable) {
        // index absent
    }
    Artisan::call('scout:sync-index-settings');
    meiliIdle();
});

afterEach(function (): void {
    try {
        meili()->deleteIndex(TEST_INDEX);
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
