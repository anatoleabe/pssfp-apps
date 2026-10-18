<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\SearchLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

uses()->group('module-3', 'library');

it('ne conserve pas l\'adresse IP des recherches', function (): void {
    $this->getJson('/v1/library/search?q=fiscalite')->assertOk();

    expect(Schema::hasColumn('search_logs', 'ip'))->toBeFalse()
        ->and(SearchLog::query()->count())->toBe(1);
});

it('purge les recherches après 90 jours et les téléchargements après 180 jours', function (): void {
    $doc = Document::factory()->published()->create();
    SearchLog::create(['query' => 'recente', 'results_count' => 1, 'searched_at' => now()->subDays(89)]);
    SearchLog::create(['query' => 'ancienne', 'results_count' => 1, 'searched_at' => now()->subDays(91)]);
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(179)]);
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(181)]);

    $this->artisan('model:prune', ['--model' => [SearchLog::class, DocumentDownload::class]])->assertExitCode(0);

    expect(SearchLog::query()->pluck('query')->all())->toBe(['recente'])
        ->and(DocumentDownload::query()->count())->toBe(1);
});

it('planifie la purge quotidienne des journaux de la bibliothèque', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->command, 'model:prune') && str_contains((string) $e->command, 'SearchLog'));

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('DocumentDownload')
        ->and($event->expression)->toBe('0 2 * * *');
});
