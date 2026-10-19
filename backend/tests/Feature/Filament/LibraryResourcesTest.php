<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
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
    $doc = Document::factory()->withFile()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('publier', $doc);

    $doc->refresh();
    expect($doc->status)->toBe(Document::STATUS_PUBLISHED)
        ->and($doc->approved_by)->toBe($this->librarian->id)
        ->and($doc->published_at)->not->toBeNull();
});

it('publie une sélection en lot', function (): void {
    $docs = Document::factory()->count(3)->withFile()->create(['status' => Document::STATUS_IN_REVIEW]);
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
    $doc = Document::factory()->withFile()->create(['status' => Document::STATUS_ARCHIVED]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('publier', $doc);

    expect($doc->fresh()->status)->toBe(Document::STATUS_PUBLISHED);
});

it('publie en lot seulement les documents en revue ou archivés', function (): void {
    $other = User::factory()->create();
    $review = Document::factory()->withFile()->create(['status' => Document::STATUS_IN_REVIEW]);
    $published = Document::factory()->create([
        'status' => Document::STATUS_PUBLISHED,
        'approved_by' => $other->id,
        'approved_at' => now()->subDay(),
    ]);
    $draft = Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    $this->actingAs($this->librarian);

    // « À valider » s'ouvre d'emblée : la sélection mêle ici tous les statuts.
    $this->livewire(ListDocuments::class)
        ->set('activeTab', 'tous')
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

it('refuse de modifier ou supprimer un document publié sans le droit de publier', function (): void {
    revokePublish($this);
    $librarian = $this->librarian->fresh();
    $published = Document::factory()->published()->withFile()->create();
    $draft = Document::factory()->create();

    expect($librarian->can('update', $published))->toBeFalse()
        ->and($librarian->can('delete', $published))->toBeFalse()
        ->and($librarian->can('update', $draft))->toBeTrue()
        ->and($librarian->can('delete', $draft))->toBeTrue();

    $this->actingAs($librarian)->get(DocumentResource::getUrl('edit', ['record' => $published]))->assertForbidden();
});

it('laisse modifier un document publié à qui peut publier', function (): void {
    $published = Document::factory()->published()->withFile()->create();

    expect($this->librarian->can('update', $published))->toBeTrue()
        ->and($this->librarian->can('delete', $published))->toBeTrue();
});

function documentForm(array $overrides = []): array
{
    return [
        'type' => 'memoire',
        'title.fr' => 'Notice de contrôle',
        'slug' => 'notice-de-controle',
        'language' => 'fr',
        'access_level' => 'public',
        ...$overrides,
    ];
}

it('interdit un lien externe avec un prix ou un accès réservé', function (array $overrides): void {
    $this->actingAs($this->librarian);

    $this->livewire(CreateDocument::class)
        ->fillForm(documentForm(['external_url' => 'https://exemple.org/ouvrage', ...$overrides]))
        ->call('create')
        ->assertHasFormErrors(['external_url']);

    expect(Document::query()->count())->toBe(0);
})->with([
    'prix' => [['price_xaf' => 2000]],
    'réservé' => [['access_level' => 'auditor']],
]);

it('interdit un lien externe à côté d\'un PDF déjà enregistré', function (): void {
    Storage::fake('minio_documents');
    $doc = Document::factory()->withFile()->create();
    $this->actingAs($this->librarian);

    $this->livewire(DocumentResource\Pages\EditDocument::class, ['record' => $doc->getRouteKey()])
        ->fillForm(['external_url' => 'https://exemple.org/ouvrage'])
        ->call('save')
        ->assertHasFormErrors(['external_url']);
});

it('refuse de publier un document sans fichier ni lien', function (): void {
    $doc = Document::factory()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian);

    try {
        DocumentResource::publish($doc);
        $this->fail('publish() aurait dû refuser.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }

    $this->livewire(ListDocuments::class)->callTableAction('publier', $doc);
    expect($doc->fresh()->status)->toBe(Document::STATUS_IN_REVIEW);
});

it('valide slug, lien et champs entiers de la notice', function (array $overrides, string $field): void {
    $this->actingAs($this->librarian);

    $this->livewire(CreateDocument::class)
        ->fillForm(documentForm($overrides))
        ->call('create')
        ->assertHasFormErrors([$field]);
})->with([
    'slug avec majuscules' => [['slug' => 'Notice De Controle'], 'slug'],
    'slug avec tiret final' => [['slug' => 'notice-'], 'slug'],
    'lien javascript' => [['external_url' => 'javascript:alert(1)'], 'external_url'],
    'lien ftp' => [['external_url' => 'ftp://exemple.org/a.pdf'], 'external_url'],
    'année décimale' => [['year' => '2024.5'], 'year'],
    'promotion décimale' => [['promotion' => '3.5'], 'promotion'],
    'pages décimales' => [['pages_count' => '12.5'], 'pages_count'],
    'prix décimal' => [['price_xaf' => '1500.5'], 'price_xaf'],
]);

it('affiche le titre français dans la liste', function (): void {
    $doc = Document::factory()->create(['title' => ['fr' => 'Titre affiché en liste']]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)
        ->assertTableColumnStateSet('title.fr', 'Titre affiché en liste', $doc)
        ->searchTable('affiché en')
        ->assertCanSeeTableRecords([$doc]);
});

it('libelle le badge d\'accès en clair', function (array $attrs, string $label): void {
    $doc = Document::factory()->create($attrs);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->assertTableColumnFormattedStateSet('access_mode', $label, $doc);
})->with([
    'libre' => [[], 'Libre'],
    'réservé' => [['access_level' => 'auditor'], 'Réservé'],
    'payant' => [['price_xaf' => 2000], 'Payant'],
]);

it('avertit quand la publication groupée ne publie rien', function (): void {
    $draft = Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)
        ->callTableBulkAction('publierLot', collect([$draft]))
        ->assertNotified(Notification::make()->title('0 document(s) publié(s), 1 ignoré(s)')->warning());
});

it('trace la publication dans le journal d\'activité', function (): void {
    $doc = Document::factory()->withFile()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('publier', $doc);

    $entry = Activity::query()
        ->where('subject_type', $doc->getMorphClass())->where('subject_id', $doc->id)
        ->where('event', 'updated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->librarian->id)
        ->and($entry->properties['attributes']['status'])->toBe(Document::STATUS_PUBLISHED)
        ->and($entry->properties['old']['status'])->toBe(Document::STATUS_IN_REVIEW);
});
