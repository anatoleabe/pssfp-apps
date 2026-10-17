<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    expect(Document::query()->published()->count())->toBe(3)
        ->and(Document::query()->where('approved_by', $this->librarian->id)->count())->toBe(3);
});

it('refuse la publication à un rôle sans la permission sensible', function (): void {
    // Réglage possible depuis l'écran Rôles : retirer « Publier » au bibliothécaire.
    $this->librarian->roles->first()->revokePermissionTo('document.publish');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $doc = Document::factory()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian->fresh());

    $this->livewire(ListDocuments::class)->assertTableActionHidden('publier', $doc);
});

function revokePublish($test): void
{
    $test->librarian->roles->first()->revokePermissionTo('document.publish');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('refuse côté serveur l\'appel de publier sans permission', function (): void {
    revokePublish($this);
    $doc = Document::factory()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian->fresh());

    $this->livewire(ListDocuments::class)->call('mountTableAction', 'publier', $doc->getKey())->call('callMountedTableAction');

    expect($doc->fresh()->status)->toBe(Document::STATUS_IN_REVIEW)
        ->and($doc->fresh()->approved_by)->toBeNull();
});

it('refuse côté serveur la publication en lot sans permission', function (): void {
    revokePublish($this);
    $docs = Document::factory()->count(2)->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian->fresh());

    $this->livewire(ListDocuments::class)->call('mountTableBulkAction', 'publierLot', $docs->modelKeys())->call('callMountedTableBulkAction');

    expect(Document::query()->published()->count())->toBe(0);
});

it('refuse publish() directement sans permission', function (): void {
    revokePublish($this);
    $doc = Document::factory()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian->fresh());

    expect(fn () => DocumentResource::publish($doc))->toThrow(HttpException::class);
    expect($doc->fresh()->status)->toBe(Document::STATUS_IN_REVIEW);
});

it('refuse soumettre à un utilisateur sans droit de modification', function (): void {
    $viewer = User::factory()->create();
    $role = Role::findOrCreate('lecteur_biblio', 'web');
    $role->givePermissionTo(['view_any_document', 'view_document']);
    $viewer->assignRole($role);
    $doc = Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    $this->actingAs($viewer);

    $this->livewire(ListDocuments::class)->call('mountTableAction', 'soumettre', $doc->getKey())->call('callMountedTableAction');

    expect($doc->fresh()->status)->toBe(Document::STATUS_DRAFT);
});

it('soumet un brouillon en revue', function (): void {
    $doc = Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('soumettre', $doc);

    expect($doc->fresh()->status)->toBe(Document::STATUS_IN_REVIEW);
});

it('ne publie pas un brouillon directement', function (): void {
    $doc = Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)
        ->assertTableActionHidden('publier', $doc)
        ->call('mountTableAction', 'publier', $doc->getKey())->call('callMountedTableAction');

    expect($doc->fresh()->status)->toBe(Document::STATUS_DRAFT);
});

it('republie un document archivé', function (): void {
    $doc = Document::factory()->create(['status' => Document::STATUS_ARCHIVED]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('publier', $doc);

    expect($doc->fresh()->status)->toBe(Document::STATUS_PUBLISHED);
});

it('publie en lot seulement les documents en revue ou archivés', function (): void {
    $other = User::factory()->create();
    $review = Document::factory()->create(['status' => Document::STATUS_IN_REVIEW]);
    $published = Document::factory()->create([
        'status' => Document::STATUS_PUBLISHED,
        'approved_by' => $other->id,
        'approved_at' => now()->subDay(),
    ]);
    $draft = Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)
        ->callTableBulkAction('publierLot', collect([$review, $published, $draft]))
        ->assertNotified('1 document(s) publié(s), 2 ignoré(s)');

    expect($review->fresh()->status)->toBe(Document::STATUS_PUBLISHED)
        ->and($review->fresh()->approved_by)->toBe($this->librarian->id)
        ->and($published->fresh()->approved_by)->toBe($other->id)
        ->and($draft->fresh()->status)->toBe(Document::STATUS_DRAFT);
});

it('archive un document publié en gardant l\'historique', function (): void {
    $doc = Document::factory()->create([
        'status' => Document::STATUS_PUBLISHED,
        'published_at' => now()->subDays(3),
        'approved_by' => $this->librarian->id,
        'approved_at' => now()->subDays(3),
    ]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('archiver', $doc);

    $doc->refresh();
    expect($doc->status)->toBe(Document::STATUS_ARCHIVED)
        ->and($doc->published_at)->not->toBeNull()
        ->and($doc->approved_by)->toBe($this->librarian->id);
});

it('refuse archiver sans permission de publication', function (): void {
    revokePublish($this);
    $doc = Document::factory()->create(['status' => Document::STATUS_PUBLISHED]);
    $this->actingAs($this->librarian->fresh());

    $this->livewire(ListDocuments::class)->assertTableActionHidden('archiver', $doc)
        ->call('mountTableAction', 'archiver', $doc->getKey())->call('callMountedTableAction');

    expect($doc->fresh()->status)->toBe(Document::STATUS_PUBLISHED);
});
