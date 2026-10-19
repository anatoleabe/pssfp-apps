<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\MotCleResource;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\UserResource;
use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Models\MotCle;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/*
 * Contributeurs de la bibliothèque (Plan 6, Task 2) : un compte
 * `library_contributor` dépose ses propres documents en brouillon, les
 * soumet en revue, et ne voit rien d'autre — ni les dépôts des autres, ni
 * les réglages d'accès et de prix, ni la publication.
 */
uses()->group('filament', 'module-3', 'library');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Bus::fake([ProcessDocumentFile::class]);
    $this->seed([RolePermissionSeeder::class]);

    $this->contributor = contributorTestUser('library_contributor');
    $this->otherContributor = contributorTestUser('library_contributor');
    $this->librarian = contributorTestUser('librarian');
});

function contributorTestUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function contributorTestDocument(User $owner, string $status = Document::STATUS_DRAFT, array $attributes = []): Document
{
    return Document::factory()->create(['uploaded_by' => $owner->id, 'status' => $status, ...$attributes]);
}

/** @return array<string, mixed> */
function contributorTestForm(array $overrides = []): array
{
    return [
        'type' => 'memoire',
        'title.fr' => 'Dépôt de contrôle',
        'slug' => 'depot-de-controle',
        'language' => 'fr',
        ...$overrides,
    ];
}

it('ouvre le panneau et l\'écran Documents au contributeur, limité à ses dépôts', function (): void {
    $mine = contributorTestDocument($this->contributor);
    $minePublished = contributorTestDocument($this->contributor, Document::STATUS_PUBLISHED);
    $others = contributorTestDocument($this->otherContributor);
    $catalogue = Document::factory()->published()->create();

    expect($this->contributor->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();

    $this->actingAs($this->contributor)->get(DocumentResource::getUrl('index'))->assertOk();

    $this->livewire(ListDocuments::class)
        ->assertCanSeeTableRecords([$mine, $minePublished])
        ->assertCanNotSeeTableRecords([$others, $catalogue])
        ->assertCountTableRecords(2);

    expect(DocumentResource::getEloquentQuery()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$mine->id, $minePublished->id])->sort()->values()->all());
});

it('crée le dépôt en brouillon à son nom, sans niveau d\'accès ni prix dans le formulaire', function (): void {
    $this->actingAs($this->contributor);

    $this->livewire(CreateDocument::class)
        ->assertFormFieldIsHidden('access_level')
        ->assertFormFieldIsHidden('price_xaf')
        ->assertFormFieldIsVisible('external_url')
        ->fillForm(contributorTestForm(['authors' => [['author_name' => 'Auteur Fictif']]]))
        ->call('create')
        ->assertHasNoFormErrors();

    $doc = Document::query()->where('slug', 'depot-de-controle')->firstOrFail();

    expect($doc->status)->toBe(Document::STATUS_DRAFT)
        ->and($doc->uploaded_by)->toBe($this->contributor->id)
        ->and($doc->access_level)->toBe('public')
        ->and($doc->price_xaf)->toBeNull()
        ->and($doc->authors->first()->author_name)->toBe('Auteur Fictif');
});

it('impose côté serveur l\'accès public, sans prix, en brouillon, même sur une requête forgée', function (): void {
    $this->actingAs($this->contributor);

    $this->livewire(CreateDocument::class)
        ->set('data.access_level', 'librarian')
        ->set('data.price_xaf', 5000)
        ->set('data.status', Document::STATUS_PUBLISHED)
        ->set('data.uploaded_by', $this->otherContributor->id)
        ->fillForm(contributorTestForm())
        ->call('create')
        ->assertHasNoFormErrors();

    $doc = Document::query()->where('slug', 'depot-de-controle')->firstOrFail();

    expect($doc->access_level)->toBe('public')
        ->and($doc->price_xaf)->toBeNull()
        ->and($doc->status)->toBe(Document::STATUS_DRAFT)
        ->and($doc->uploaded_by)->toBe($this->contributor->id);
});

it('accepte un lien externe à la place du PDF', function (): void {
    $this->actingAs($this->contributor);

    $this->livewire(CreateDocument::class)
        ->fillForm(contributorTestForm(['external_url' => 'https://exemple.org/ouvrage']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Document::query()->where('slug', 'depot-de-controle')->value('external_url'))->toBe('https://exemple.org/ouvrage');
});

it('écarte côté serveur l\'accès et le prix des données d\'un contributeur, pas de celles du bibliothécaire', function (): void {
    $tampered = ['year' => 2024, 'access_level' => 'librarian', 'price_xaf' => 5000];

    $this->actingAs($this->contributor);
    expect(DocumentResource::managesCatalogue())->toBeFalse()
        ->and(DocumentResource::enforceContributorCreateData($tampered))->toBe(['year' => 2024, 'access_level' => 'public', 'price_xaf' => null])
        ->and(DocumentResource::enforceContributorUpdateData($tampered))->toBe(['year' => 2024]);

    $this->actingAs($this->librarian);
    expect(DocumentResource::managesCatalogue())->toBeTrue()
        ->and(DocumentResource::enforceContributorCreateData($tampered))->toBe($tampered)
        ->and(DocumentResource::enforceContributorUpdateData($tampered))->toBe($tampered);
});

it('modifie son brouillon sans toucher à l\'accès ni au prix fixés par la bibliothèque', function (): void {
    $draft = contributorTestDocument($this->contributor, attributes: ['year' => 2020, 'access_level' => 'auditor', 'price_xaf' => 2000]);
    $this->actingAs($this->contributor);

    $this->livewire(EditDocument::class, ['record' => $draft->getRouteKey()])
        ->assertFormFieldIsHidden('access_level')
        ->assertFormFieldIsHidden('price_xaf')
        ->set('data.access_level', 'public')
        ->set('data.price_xaf', null)
        ->fillForm(['year' => 2024])
        ->call('save')
        ->assertHasNoFormErrors();

    $draft->refresh();
    expect($draft->year)->toBe(2024)
        ->and($draft->access_level)->toBe('auditor')
        ->and($draft->price_xaf)->toBe(2000)
        ->and($draft->status)->toBe(Document::STATUS_DRAFT);
});

it('verrouille ses dépôts sortis du brouillon', function (string $status): void {
    $doc = contributorTestDocument($this->contributor, $status);

    expect($this->contributor->can('update', $doc))->toBeFalse()
        ->and($this->contributor->can('delete', $doc))->toBeFalse()
        ->and($this->contributor->can('view', $doc))->toBeTrue();

    $this->actingAs($this->contributor)->get(DocumentResource::getUrl('edit', ['record' => $doc]))->assertForbidden();
})->with([Document::STATUS_IN_REVIEW, Document::STATUS_PUBLISHED, Document::STATUS_ARCHIVED]);

it('refuse le brouillon d\'un autre, même par une adresse forgée', function (): void {
    $others = contributorTestDocument($this->otherContributor);
    $orphan = Document::factory()->create(['uploaded_by' => null]);

    foreach ([$others, $orphan] as $doc) {
        expect($this->contributor->can('view', $doc))->toBeFalse()
            ->and($this->contributor->can('update', $doc))->toBeFalse()
            ->and($this->contributor->can('delete', $doc))->toBeFalse();

        $status = $this->actingAs($this->contributor)
            ->get(DocumentResource::getUrl('edit', ['record' => $doc]))
            ->getStatusCode();

        expect($status)->toBeIn([403, 404]);
    }
});

it('refuse l\'enregistrement d\'un brouillon soumis entre-temps', function (): void {
    $draft = contributorTestDocument($this->contributor, attributes: ['year' => 2020]);
    $this->actingAs($this->contributor);

    $page = $this->livewire(EditDocument::class, ['record' => $draft->getRouteKey()])
        ->fillForm(['year' => 2025]);
    $draft->update(['status' => Document::STATUS_IN_REVIEW]);

    $page->call('save')->assertForbidden();
    expect($draft->fresh()->year)->toBe(2020);
});

it('soumet son brouillon en revue, qui lui devient alors fermé', function (): void {
    $draft = contributorTestDocument($this->contributor);
    $this->actingAs($this->contributor);

    $this->livewire(ListDocuments::class)
        ->assertTableActionVisible('soumettre', $draft)
        ->callTableAction('soumettre', $draft);

    $draft->refresh();
    expect($draft->status)->toBe(Document::STATUS_IN_REVIEW)
        ->and($this->contributor->can('update', $draft))->toBeFalse();
});

it('ne propose au contributeur ni publication, ni archivage, ni action en lot, ni filtre d\'import', function (): void {
    $inReview = contributorTestDocument($this->contributor, Document::STATUS_IN_REVIEW, ['file_path' => 'documents/a.pdf']);
    $published = contributorTestDocument($this->contributor, Document::STATUS_PUBLISHED);
    $this->actingAs($this->contributor);

    $this->livewire(ListDocuments::class)
        ->assertTableActionHidden('publier', $inReview)
        ->assertTableActionHidden('archiver', $published)
        ->assertTableActionHidden('edit', $inReview)
        ->assertTableBulkActionHidden('publierLot')
        ->assertTableFilterHidden('legacy_source')
        ->assertTableFilterVisible('mes_depots')
        ->call('mountTableAction', 'publier', $inReview->getKey())->call('callMountedTableAction');

    expect($inReview->fresh()->status)->toBe(Document::STATUS_IN_REVIEW)
        ->and($this->contributor->can('publish', $inReview))->toBeFalse();
});

it('supprime ses brouillons et seulement eux', function (): void {
    $draft = contributorTestDocument($this->contributor);
    $others = contributorTestDocument($this->otherContributor);
    $this->actingAs($this->contributor);

    $this->livewire(EditDocument::class, ['record' => $draft->getRouteKey()])
        ->assertActionVisible(DeleteAction::class)
        ->callAction(DeleteAction::class);

    expect(Document::query()->find($draft->id))->toBeNull()
        ->and($this->contributor->can('delete', $others))->toBeFalse()
        ->and($this->contributor->can('deleteAny', Document::class))->toBeFalse()
        ->and($this->contributor->can('forceDelete', $draft))->toBeFalse();
});

it('n\'ouvre au contributeur ni le thésaurus en écriture, ni les autres modules', function (): void {
    $this->actingAs($this->contributor);

    expect($this->contributor->can('create', MotCle::class))->toBeFalse()
        ->and($this->contributor->can('update_mot::cle'))->toBeFalse()
        ->and(MotCleResource::canAccess())->toBeFalse();

    // Le dépôt choisit parmi les mots-clés existants, sans en créer.
    $this->livewire(CreateDocument::class)
        ->assertFormFieldExists('motsCles', checkFieldUsing: fn (Select $field): bool => ! $field->hasCreateOptionActionFormSchema());

    $this->get(MotCleResource::getUrl('index'))->assertForbidden();
    $this->get(CandidatureResource::getUrl('index'))->assertForbidden();
    $this->get(PageResource::getUrl('index'))->assertForbidden();
    $this->get(UserResource::getUrl('index'))->assertForbidden();
});

it('laisse le bibliothécaire voir tous les documents et filtrer ses dépôts', function (): void {
    $contributed = contributorTestDocument($this->contributor);
    $mine = contributorTestDocument($this->librarian);
    $catalogue = Document::factory()->published()->create();
    $this->actingAs($this->librarian);

    expect(DocumentResource::getEloquentQuery()->count())->toBe(3);

    $this->livewire(ListDocuments::class)
        ->assertCanSeeTableRecords([$contributed, $mine, $catalogue])
        ->assertTableFilterVisible('legacy_source')
        ->filterTable('mes_depots')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$contributed, $catalogue]);

    // Le bibliothécaire garde la main sur le dépôt d'un contributeur.
    expect($this->librarian->can('update', $contributed))->toBeTrue();

    $this->livewire(CreateDocument::class)
        ->assertFormFieldIsVisible('access_level')
        ->assertFormFieldIsVisible('price_xaf');
});

it('limite la recherche globale du contributeur à ses dépôts', function (): void {
    $mine = contributorTestDocument($this->contributor, attributes: ['slug' => 'alpha-mon-depot', 'title' => ['fr' => 'Alpha mon dépôt']]);
    contributorTestDocument($this->otherContributor, attributes: ['slug' => 'alpha-depot-voisin', 'title' => ['fr' => 'Alpha dépôt voisin']]);
    Document::factory()->published()->create(['slug' => 'alpha-catalogue', 'title' => ['fr' => 'Alpha catalogue']]);
    $this->actingAs($this->contributor);

    $titles = DocumentResource::getGlobalSearchResults('alpha')->map(fn ($result): string => (string) $result->title)->all();

    expect($titles)->toBe([$mine->getTranslation('title', 'fr')]);
});

it('refuse « Soumettre en revue » sur le brouillon d\'un autre, même par une clé forgée', function (): void {
    $others = contributorTestDocument($this->otherContributor);
    $this->actingAs($this->contributor);

    $this->livewire(ListDocuments::class)
        ->call('mountTableAction', 'soumettre', (string) $others->getKey())
        ->call('callMountedTableAction');

    expect($others->fresh()->status)->toBe(Document::STATUS_DRAFT);
});

it('explique au contributeur qu\'un lien externe exclut son PDF', function (): void {
    Storage::fake('minio_documents');
    $draft = contributorTestDocument($this->contributor, attributes: ['file_path' => 'documents/mon-depot.pdf']);
    $this->actingAs($this->contributor);

    $page = $this->livewire(EditDocument::class, ['record' => $draft->getRouteKey()])
        ->fillForm(['external_url' => 'https://exemple.org/ouvrage'])
        ->call('save')
        ->assertHasFormErrors(['external_url']);

    expect($page->errors()->first('data.external_url'))->toBe('Un lien externe exclut le PDF : retirez le PDF ou videz le lien.');
});

it('refuse un lien externe sur un dépôt que la bibliothèque a réservé ou tarifé', function (): void {
    $draft = contributorTestDocument($this->contributor, attributes: ['access_level' => 'auditor']);
    $this->actingAs($this->contributor);

    $page = $this->livewire(EditDocument::class, ['record' => $draft->getRouteKey()])
        ->fillForm(['external_url' => 'https://exemple.org/ouvrage'])
        ->call('save')
        ->assertHasFormErrors(['external_url']);

    expect($page->errors()->first('data.external_url'))->toBe('La bibliothèque a réservé ou tarifé ce document : il ne peut pas renvoyer vers un lien externe.');

    expect($draft->fresh()->external_url)->toBeNull();
});
