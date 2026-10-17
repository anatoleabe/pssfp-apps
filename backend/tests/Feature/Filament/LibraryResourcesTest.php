<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\PermissionRegistrar;

uses()->group('filament', 'module-3');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Bus::fake([ProcessDocumentFile::class]);
    $this->seed([RolePermissionSeeder::class]);

    $this->librarian = User::factory()->create();
    $this->librarian->assignRole('librarian');
    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('ouvre la liste des documents au bibliothécaire et la ferme à l\'éditeur', function (): void {
    $this->actingAs($this->librarian)->get('/admin/documents')->assertOk();
    $this->actingAs($this->editor)->get('/admin/documents')->assertForbidden();
});

it('crée un document en brouillon avec ses auteurs', function (): void {
    $this->actingAs($this->librarian);

    $this->livewire(CreateDocument::class)
        ->fillForm([
            'type' => 'memoire',
            'title.fr' => 'Budget-programme et performance',
            'slug' => 'budget-programme-et-performance',
            'language' => 'fr',
            'access_level' => 'public',
            'year' => 2024,
            'authors' => [['author_name' => 'Auteur Fictif', 'order' => 1]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $doc = Document::query()->where('slug', 'budget-programme-et-performance')->firstOrFail();
    expect($doc->status)->toBe(Document::STATUS_DRAFT)
        ->and($doc->authors->first()->author_name)->toBe('Auteur Fictif');
});

it('publie un document et trace le validateur', function (): void {
    $doc = Document::factory()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('publier', $doc);

    $doc->refresh();
    expect($doc->status)->toBe(Document::STATUS_PUBLISHED)
        ->and($doc->approved_by)->toBe($this->librarian->id)
        ->and($doc->published_at)->not->toBeNull();
});

it('publie une sélection en lot', function (): void {
    $docs = Document::factory()->count(3)->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableBulkAction('publierLot', $docs);

    expect(Document::query()->published()->count())->toBe(3);
});

it('refuse la publication à un rôle sans la permission sensible', function (): void {
    // Réglage possible depuis l'écran Rôles : retirer « Publier » au bibliothécaire.
    $this->librarian->roles->first()->revokePermissionTo('document.publish');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $doc = Document::factory()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian->fresh());

    $this->livewire(ListDocuments::class)->assertTableActionHidden('publier', $doc);
});
