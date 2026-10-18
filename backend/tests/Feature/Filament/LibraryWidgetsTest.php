<?php

declare(strict_types=1);

use App\Filament\Widgets\LibraryOverview;
use App\Filament\Widgets\LibraryZeroResultSearches;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\SearchLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;

uses()->group('filament', 'module-3');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([RolePermissionSeeder::class]);
    $this->librarian = User::factory()->create();
    $this->librarian->assignRole('librarian');
});

function overviewStats(): array
{
    $method = new ReflectionMethod(LibraryOverview::class, 'getStats');
    $stats = $method->invoke(new LibraryOverview);

    return collect($stats)->mapWithKeys(fn ($s): array => [(string) $s->getLabel() => $s])->all();
}

it('affiche les compteurs de la bibliothèque avec les valeurs exactes', function (): void {
    $doc = Document::factory()->published()->create(['type' => 'memoire']);
    Document::factory()->published()->create(['type' => 'memoire']);
    Document::factory()->published()->create(['type' => 'these']);
    Document::factory()->create(['status' => Document::STATUS_IN_REVIEW, 'type' => 'cours']);
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(3)]);
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(40)]);

    $this->actingAs($this->librarian);
    $stats = overviewStats();

    expect($stats['Documents publiés']->getValue())->toBe(3)
        ->and($stats['En attente de validation']->getValue())->toBe(1)
        ->and($stats['Téléchargements (30 j)']->getValue())->toBe(1)
        ->and($stats['Par type']->getValue())->toBe(2)
        ->and((string) $stats['Par type']->getDescription())->toBe('Mémoire 2 · Thèse 1');

    $this->livewire(LibraryOverview::class)->assertSee('Mémoire 2 · Thèse 1');
});

it('classe les recherches sans résultat récentes, par fréquence', function (): void {
    foreach (['cadastre', 'loi de finances 2027', 'loi de finances 2027'] as $q) {
        SearchLog::create(['query' => $q, 'results_count' => 0, 'searched_at' => now()->subDay()]);
    }
    SearchLog::create(['query' => 'tva', 'results_count' => 12, 'searched_at' => now()->subDay()]);
    SearchLog::create(['query' => 'ancienne requete', 'results_count' => 0, 'searched_at' => now()->subDays(45)]);

    $this->actingAs($this->librarian);
    $component = $this->livewire(LibraryZeroResultSearches::class)
        ->assertSeeInOrder(['loi de finances 2027', 'cadastre'])
        ->assertDontSee('tva')
        ->assertDontSee('ancienne requete');

    $rows = $component->instance()->getTableRecords()->map(fn ($r): array => [$r->query, (int) $r->total])->all();
    expect($rows)->toBe([['loi de finances 2027', 2], ['cadastre', 1]]);
});

it('affiche les widgets au bibliothécaire', function (): void {
    $this->actingAs($this->librarian);

    expect(LibraryOverview::canView())->toBeTrue()->and(LibraryZeroResultSearches::canView())->toBeTrue();
});

it('planifie la réindexation puis le contrôle de cohérence', function (): void {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

    expect($commands)->toContain('scout:import')->and($commands)->toContain('library:check-index');
});

it('masque les widgets aux rôles sans accès bibliothèque', function (): void {
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    $this->actingAs($editor);

    expect(LibraryOverview::canView())->toBeFalse()->and(LibraryZeroResultSearches::canView())->toBeFalse();
});

it('rafraîchit le tableau de bord bibliothèque toutes les minutes', function (): void {
    $method = new ReflectionMethod(LibraryOverview::class, 'getPollingInterval');

    expect($method->invoke(new LibraryOverview))->toBe('60s');
});
