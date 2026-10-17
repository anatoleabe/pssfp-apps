<?php

declare(strict_types=1);

use App\Models\Document;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;

uses()->group('module-3', 'library', 'meilisearch');

function checkMeili(): Client
{
    return new Client(config('scout.meilisearch.host'), config('scout.meilisearch.key'));
}

function checkMeiliIdle(): void
{
    $deadline = microtime(true) + 15;
    do {
        $pending = checkMeili()->getTasks((new TasksQuery)->setStatuses(['enqueued', 'processing']))->getResults();
    } while ($pending !== [] && microtime(true) < $deadline && usleep(50_000) === null);
}

it('ignore le contrôle hors Meilisearch', function (): void {
    config(['scout.driver' => 'collection']);

    $this->artisan('library:check-index')->expectsOutputToContain('ignoré')->assertExitCode(0);
});

describe('avec Meilisearch', function (): void {
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

        $key = config('scout.meilisearch.key');
        if (! $key && is_file(base_path('.env'))) {
            $key = Dotenv\Dotenv::parse((string) file_get_contents(base_path('.env')))['MEILISEARCH_KEY'] ?? null;
        }

        config(['scout.meilisearch.key' => $key, 'scout.driver' => 'meilisearch', 'scout.prefix' => 'test_', 'scout.queue' => false]);
        $this->testIndex = (new Document)->searchableAs();
        expect($this->testIndex)->toStartWith('test_');

        try {
            checkMeili()->deleteIndex($this->testIndex);
            checkMeiliIdle();
        } catch (Throwable) {
            // index absent
        }
        Artisan::call('scout:sync-index-settings');
        checkMeiliIdle();
    });

    afterEach(function (): void {
        if (! isset($this->testIndex) || ! str_starts_with($this->testIndex, 'test_')) {
            return;
        }
        try {
            checkMeili()->deleteIndex($this->testIndex);
            checkMeiliIdle();
        } catch (Throwable) {
            // rien à nettoyer
        }
    });

    it('réussit quand base et index concordent', function (): void {
        Document::factory()->published()->count(2)->create();
        checkMeiliIdle();

        $this->artisan('library:check-index')->expectsOutputToContain('Base : 2 · Index : 2')->assertExitCode(0);
    });

    it('échoue et journalise quand l\'index diverge', function (): void {
        Document::factory()->published()->count(2)->create();
        Document::withoutSyncingToSearch(fn () => Document::factory()->published()->create());
        checkMeiliIdle();

        Log::spy();
        $this->artisan('library:check-index')->expectsOutputToContain('Base : 3 · Index : 2')->assertExitCode(1);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $m, array $ctx): bool => $ctx === ['database' => 3, 'index' => 2]
        );
    });
});

it('échoue proprement quand Meilisearch est injoignable', function (): void {
    config(['scout.driver' => 'meilisearch', 'scout.meilisearch.host' => 'http://127.0.0.1:1', 'scout.prefix' => 'test_']);
    Log::spy();

    $this->artisan('library:check-index')->expectsOutputToContain('Meilisearch')->assertExitCode(1);

    Log::shouldHaveReceived('warning')->once();
});
