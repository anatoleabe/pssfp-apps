<?php

declare(strict_types=1);

use App\Filament\Widgets\LibraryOverview;
use App\Filament\Widgets\LibraryZeroResultSearches;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\SearchLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'module-3');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([RolePermissionSeeder::class]);
    $this->librarian = User::factory()->create();
    $this->librarian->assignRole('librarian');
});

it('affiche les compteurs de la bibliothèque', function (): void {
    $doc = Document::factory()->published()->create();
    Document::factory()->create();
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(3)]);
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(40)]);

    $this->actingAs($this->librarian);
    $this->livewire(LibraryOverview::class)
        ->assertSee('Documents publiés')->assertSee('1')
        ->assertSee('Téléchargements (30 j)');
});

it('classe les recherches sans résultat les plus fréquentes', function (): void {
    foreach (['loi de finances 2027', 'loi de finances 2027', 'cadastre'] as $q) {
        SearchLog::create(['query' => $q, 'results_count' => 0, 'searched_at' => now()->subDay()]);
    }
    SearchLog::create(['query' => 'tva', 'results_count' => 12, 'searched_at' => now()->subDay()]);

    $this->actingAs($this->librarian);
    $this->livewire(LibraryZeroResultSearches::class)
        ->assertSee('loi de finances 2027')->assertSee('cadastre')->assertDontSee('tva');
});

it('masque les widgets aux rôles sans accès bibliothèque', function (): void {
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    $this->actingAs($editor);

    expect(LibraryOverview::canView())->toBeFalse()->and(LibraryZeroResultSearches::canView())->toBeFalse();
});
