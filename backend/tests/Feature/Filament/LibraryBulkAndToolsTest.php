<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Pages\ViewDocument;
use App\Filament\Resources\DocumentResource\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\DocumentResource\RelationManagers\LegacyRedirectsRelationManager;
use App\Jobs\ProcessDocumentFile;
use App\Jobs\SendThrottledMail;
use App\Mail\LibraryDocumentsSubmittedMail;
use App\Mail\LibraryDocumentSubmittedMail;
use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\MotCle;
use App\Models\Role;
use App\Models\User;
use App\Services\Library\Files\CoverRenderer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Actions en lot et outils de fiche de la bibliothèque (Plan 6, Task 4) :
 * type, thèmes, archivage et soumission en lot ; couverture régénérée ou
 * personnalisée ; historique et anciennes adresses de la fiche.
 */
uses()->group('filament', 'module-3', 'library');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Bus::fake([ProcessDocumentFile::class, SendThrottledMail::class]);
    $this->seed([RolePermissionSeeder::class]);

    $this->librarian = bulkToolsUser('librarian');
});

function bulkToolsUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user->fresh();
}

/** Gère le catalogue sans pouvoir publier (rôle composé dans l'écran Rôles). */
function bulkToolsCataloguer(): User
{
    $role = Role::create(['name' => 'catalogueur', 'label' => 'Catalogueur', 'guard_name' => 'web']);
    $role->givePermissionTo([
        'view_any_document', 'view_document', 'create_document', 'update_document',
        'view_any_mot::cle', 'view_mot::cle',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return bulkToolsUser('catalogueur');
}

function bulkToolsDocument(string $status, array $attributes = []): Document
{
    return Document::factory()->withFile()->create([
        'status' => $status,
        'published_at' => $status === Document::STATUS_PUBLISHED ? now()->subDay() : null,
        ...$attributes,
    ]);
}

/**
 * Moteur de recherche qui note ce qu'on lui envoie (le pilote « null » des
 * tests ne garde rien).
 */
function bulkToolsRecordingEngine(): NullEngine
{
    $engine = new class extends NullEngine
    {
        /** @var array<int, array<string, mixed>> */
        public array $updated = [];

        /** @var list<int> */
        public array $deleted = [];

        public function update($models)
        {
            foreach ($models as $model) {
                $this->updated[$model->getKey()] = $model->toSearchableArray();
            }
        }

        public function delete($models)
        {
            foreach ($models as $model) {
                $this->deleted[] = $model->getKey();
            }
        }
    };

    config()->set('scout.queue', false);
    config()->set('scout.driver', 'recording');
    app(EngineManager::class)->extend('recording', fn () => $engine);
    app(EngineManager::class)->forgetDrivers();

    return $engine;
}

function bulkToolsList(): mixed
{
    return test()->livewire(ListDocuments::class)->set('activeTab', 'tous');
}

it('change le type de la sélection et resynchronise l\'index', function (): void {
    $engine = bulkToolsRecordingEngine();
    $draft = bulkToolsDocument(Document::STATUS_DRAFT);
    $published = bulkToolsDocument(Document::STATUS_PUBLISHED);
    $engine->updated = [];
    $this->actingAs($this->librarian);

    bulkToolsList()
        ->callTableBulkAction('changerType', [$draft, $published], data: ['type' => 'these'])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('2 document(s) modifié(s)');

    expect($draft->fresh()->type)->toBe('these')
        ->and($published->fresh()->type)->toBe('these')
        ->and($engine->updated[$published->id]['type'] ?? null)->toBe('these')
        ->and(Activity::query()->where('subject_id', $published->id)->where('event', 'updated')->latest('id')->first()?->properties['attributes']['type'] ?? null)
        ->toBe('these');
});

it('ignore les documents publiés quand le compte ne peut pas publier, et les compte', function (): void {
    $draft = bulkToolsDocument(Document::STATUS_DRAFT);
    $published = bulkToolsDocument(Document::STATUS_PUBLISHED);
    $this->actingAs(bulkToolsCataloguer());

    bulkToolsList()
        ->callTableBulkAction('changerType', [$draft, $published], data: ['type' => 'rapport'])
        ->assertNotified('1 document(s) modifié(s) — 1 document(s) publiés ignorés');

    expect($draft->fresh()->type)->toBe('rapport')
        ->and($published->fresh()->type)->toBe('memoire');
});

it('ajoute des thèmes sans doublon, trace l\'ajout et réindexe', function (): void {
    $engine = bulkToolsRecordingEngine();
    [$existing, $added] = MotCle::factory()->count(2)->create();
    $published = bulkToolsDocument(Document::STATUS_PUBLISHED);
    $published->motsCles()->attach($existing);
    $draft = bulkToolsDocument(Document::STATUS_DRAFT);
    $engine->updated = [];
    $this->actingAs($this->librarian);

    bulkToolsList()
        ->callTableBulkAction('ajouterThemes', [$published, $draft], data: ['mots_cles' => [$existing->id, $added->id]])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('2 document(s) modifié(s)');

    expect($published->motsCles()->pluck('mots_cles.id')->sort()->values()->all())->toBe(collect([$existing->id, $added->id])->sort()->values()->all())
        ->and($draft->motsCles()->pluck('mots_cles.id')->sort()->values()->all())->toBe(collect([$existing->id, $added->id])->sort()->values()->all())
        ->and($engine->updated[$published->id]['mots_cles'] ?? [])->toContain($added->slug);

    $log = Activity::query()->where('subject_id', $published->id)->where('event', 'mots_cles_added')->sole();
    expect($log->causer_id)->toBe($this->librarian->id)
        ->and($log->properties['mots_cles'])->toBe([$added->slug]);
});

it('n\'ajoute pas de thème à un document publié sans le droit de publier', function (): void {
    $theme = MotCle::factory()->create();
    $published = bulkToolsDocument(Document::STATUS_PUBLISHED);
    $draft = bulkToolsDocument(Document::STATUS_DRAFT);
    $this->actingAs(bulkToolsCataloguer());

    bulkToolsList()
        ->callTableBulkAction('ajouterThemes', [$published, $draft], data: ['mots_cles' => [$theme->id]])
        ->assertNotified('1 document(s) modifié(s) — 1 document(s) publiés ignorés');

    expect($published->motsCles()->count())->toBe(0)
        ->and($draft->motsCles()->count())->toBe(1);
});

it('retire des thèmes de la sélection et trace le retrait', function (): void {
    [$kept, $removed] = MotCle::factory()->count(2)->create();
    $doc = bulkToolsDocument(Document::STATUS_DRAFT);
    $doc->motsCles()->attach([$kept->id, $removed->id]);
    $untouched = bulkToolsDocument(Document::STATUS_DRAFT);
    $untouched->motsCles()->attach($kept);
    $this->actingAs($this->librarian);

    bulkToolsList()
        ->callTableBulkAction('retirerThemes', [$doc, $untouched], data: ['mots_cles' => [$removed->id]])
        ->assertNotified('1 document(s) modifié(s)');

    expect($doc->motsCles()->pluck('mots_cles.id')->all())->toBe([$kept->id])
        ->and($untouched->motsCles()->pluck('mots_cles.id')->all())->toBe([$kept->id])
        ->and(Activity::query()->where('subject_id', $doc->id)->where('event', 'mots_cles_removed')->sole()->properties['mots_cles'])->toBe([$removed->slug])
        ->and(Activity::query()->where('subject_id', $untouched->id)->where('event', 'mots_cles_removed')->exists())->toBeFalse();
});

it('archive les documents publiés de la sélection et les retire de l\'index', function (): void {
    $engine = bulkToolsRecordingEngine();
    $published = bulkToolsDocument(Document::STATUS_PUBLISHED);
    $draft = bulkToolsDocument(Document::STATUS_DRAFT);
    $this->actingAs($this->librarian);

    bulkToolsList()
        ->callTableBulkAction('archiverLot', [$published, $draft])
        ->assertNotified('1 document(s) archivé(s), 1 ignoré(s)');

    expect($published->fresh()->status)->toBe(Document::STATUS_ARCHIVED)
        ->and($draft->fresh()->status)->toBe(Document::STATUS_DRAFT)
        ->and($engine->deleted)->toContain($published->id);
});

it('réserve l\'archivage en lot au droit de publier', function (): void {
    $published = bulkToolsDocument(Document::STATUS_PUBLISHED);
    $this->actingAs(bulkToolsCataloguer());

    bulkToolsList()
        ->assertTableBulkActionHidden('archiverLot')
        ->assertTableBulkActionVisible('changerType');

    expect(fn () => DocumentResource::archive($published))->toThrow(HttpException::class)
        ->and($published->fresh()->status)->toBe(Document::STATUS_PUBLISHED);
});

it('soumet les brouillons de la sélection avec un seul message par validateur', function (): void {
    $manager = bulkToolsUser('library_manager');
    [$a, $b] = [bulkToolsDocument(Document::STATUS_DRAFT), bulkToolsDocument(Document::STATUS_DRAFT)];
    $published = bulkToolsDocument(Document::STATUS_PUBLISHED);
    $this->actingAs($this->librarian);

    bulkToolsList()
        ->callTableBulkAction('soumettreLot', [$a, $b, $published])
        ->assertNotified('2 document(s) soumis en revue, 1 ignoré(s)');

    expect($a->fresh()->status)->toBe(Document::STATUS_IN_REVIEW)
        ->and($b->fresh()->status)->toBe(Document::STATUS_IN_REVIEW)
        ->and($published->fresh()->status)->toBe(Document::STATUS_PUBLISHED);

    $jobs = Bus::dispatched(SendThrottledMail::class)->values();
    $read = fn (SendThrottledMail $job, string $property): mixed => (new ReflectionProperty($job, $property))->getValue($job);

    expect($jobs)->toHaveCount(1)
        ->and($read($jobs[0], 'destinataires'))->toBe([$manager->email])
        ->and($read($jobs[0], 'mailable'))->toBeInstanceOf(LibraryDocumentsSubmittedMail::class)
        ->and($read($jobs[0], 'mailable')->documents)->toHaveCount(2);
});

it('garde le message habituel quand le lot ne soumet qu\'un document', function (): void {
    bulkToolsUser('library_manager');
    $draft = bulkToolsDocument(Document::STATUS_DRAFT);
    $this->actingAs($this->librarian);

    bulkToolsList()->callTableBulkAction('soumettreLot', [$draft]);

    Bus::assertDispatched(SendThrottledMail::class, fn (SendThrottledMail $job): bool => (new ReflectionProperty($job, 'mailable'))->getValue($job) instanceof LibraryDocumentSubmittedMail);
});

it('ne propose aucune action en lot au contributeur ni à la consultation', function (string $role): void {
    $user = bulkToolsUser($role);
    if ($role === 'library_contributor') {
        bulkToolsDocument(Document::STATUS_DRAFT, ['uploaded_by' => $user->id]);
    }
    $this->actingAs($user);

    bulkToolsList()
        ->assertTableBulkActionHidden('changerType')
        ->assertTableBulkActionHidden('ajouterThemes')
        ->assertTableBulkActionHidden('retirerThemes')
        ->assertTableBulkActionHidden('archiverLot')
        ->assertTableBulkActionHidden('soumettreLot');
})->with(['library_contributor', 'library_viewer']);

it('relance le traitement du PDF en mode forcé depuis la fiche', function (): void {
    $doc = bulkToolsDocument(Document::STATUS_DRAFT);
    Bus::fake([ProcessDocumentFile::class, SendThrottledMail::class]);
    $this->actingAs($this->librarian);

    $this->livewire(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->callAction('regenererCouverture')
        ->assertNotified('Régénération lancée');

    Bus::assertDispatched(ProcessDocumentFile::class, fn (ProcessDocumentFile $job): bool => $job->documentId === $doc->id && $job->forceCover);
});

it('ne propose la régénération ni sans PDF, ni au contributeur', function (): void {
    $external = Document::factory()->external()->create();
    $contributor = bulkToolsUser('library_contributor');
    $own = bulkToolsDocument(Document::STATUS_DRAFT, ['uploaded_by' => $contributor->id]);

    $this->actingAs($this->librarian);
    $this->livewire(EditDocument::class, ['record' => $external->getRouteKey()])->assertActionHidden('regenererCouverture');

    $this->actingAs($contributor);
    $this->livewire(EditDocument::class, ['record' => $own->getRouteKey()])
        ->assertActionHidden('regenererCouverture')
        ->assertActionHidden('couverturePersonnalisee');
});

it('remplace la couverture par une image déposée sur le disque des couvertures', function (): void {
    Storage::fake('minio_media');
    $doc = bulkToolsDocument(Document::STATUS_DRAFT, ['cover_path' => 'library/covers/auto.jpg']);
    $this->actingAs($this->librarian);

    $this->livewire(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->callAction('couverturePersonnalisee', data: ['cover' => UploadedFile::fake()->image('couverture.jpg', 300, 420)])
        ->assertHasNoActionErrors()
        ->assertNotified('Couverture personnalisée enregistrée');

    $doc->refresh();
    expect($doc->cover_path)->toStartWith(Document::customCoversDirectory().'/')
        ->and($doc->hasCustomCover())->toBeTrue();
    Storage::disk('minio_media')->assertExists($doc->cover_path);
    expect(Activity::query()->where('subject_id', $doc->id)->latest('id')->first()?->properties['attributes']['cover_path'] ?? null)
        ->toBe($doc->cover_path);
});

it('refuse un chemin forgé dans le champ de couverture personnalisée', function (): void {
    Storage::fake('minio_media');
    $doc = bulkToolsDocument(Document::STATUS_DRAFT);
    $this->actingAs($this->librarian);

    $this->livewire(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->mountAction('couverturePersonnalisee')
        ->set('mountedActionsData.0.cover', ['x' => 'library/covers/custom/autre-document.jpg'])
        ->callMountedAction()
        ->assertHasActionErrors(['cover']);

    expect($doc->fresh()->cover_path)->toBeNull();
});

it('garde la couverture personnalisée au retraitement automatique, la remplace en mode forcé', function (): void {
    Storage::fake('minio_documents');
    Storage::fake('minio_media');
    $doc = bulkToolsDocument(Document::STATUS_DRAFT);
    Storage::disk('minio_documents')->put($doc->file_path, '%PDF-1.4 contenu');
    $custom = Document::customCoversDirectory().'/perso.jpg';
    Document::withoutEvents(fn () => $doc->update(['cover_path' => $custom]));
    $renderer = new class implements CoverRenderer
    {
        public ?string $jpeg = 'JPEGDATA';

        public function render(string $pdfPath): ?string
        {
            return $this->jpeg;
        }
    };

    (new ProcessDocumentFile($doc->id))->handle($renderer);
    expect($doc->fresh()->cover_path)->toBe($custom)
        ->and($doc->fresh()->file_checksum_md5)->toBe(md5('%PDF-1.4 contenu'));

    $renderer->jpeg = null;
    (new ProcessDocumentFile($doc->id, forceCover: true))->handle($renderer);
    expect($doc->fresh()->cover_path)->toBe($custom);

    $renderer->jpeg = 'JPEGDATA';
    (new ProcessDocumentFile($doc->id, forceCover: true))->handle($renderer);
    expect($doc->fresh()->cover_path)->toBe("library/covers/{$doc->uuid}.jpg")
        ->and($doc->fresh()->hasCustomCover())->toBeFalse();
});

it('montre l\'historique de la fiche, du plus récent au plus ancien, en lecture seule', function (): void {
    $this->actingAs($this->librarian);
    $doc = bulkToolsDocument(Document::STATUS_DRAFT);
    $doc->update(['status' => Document::STATUS_IN_REVIEW]);
    $entries = Activity::query()->where('subject_type', $doc->getMorphClass())->where('subject_id', $doc->id)->orderByDesc('id')->get();

    expect($entries)->toHaveCount(2);

    $page = $this->livewire(ActivitiesRelationManager::class, ['ownerRecord' => $doc, 'pageClass' => EditDocument::class])
        ->assertCanSeeTableRecords($entries, inOrder: true)
        ->assertSee($this->librarian->name)
        ->assertSee('Statut : Brouillon → En revue')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');

    expect($page->instance()->isReadOnly())->toBeTrue();
});

it('montre l\'historique à qui voit la fiche, pas aux autres modules', function (): void {
    $contributor = bulkToolsUser('library_contributor');
    $own = bulkToolsDocument(Document::STATUS_DRAFT, ['uploaded_by' => $contributor->id]);
    $other = bulkToolsDocument(Document::STATUS_DRAFT);

    $this->actingAs($contributor);
    expect(ActivitiesRelationManager::canViewForRecord($own, EditDocument::class))->toBeTrue()
        ->and(ActivitiesRelationManager::canViewForRecord($other, EditDocument::class))->toBeFalse();

    $this->actingAs(bulkToolsUser('editor'));
    expect(ActivitiesRelationManager::canViewForRecord($own, EditDocument::class))->toBeFalse();
});

it('liste les anciennes adresses qui mènent à la fiche, avec leurs visites', function (): void {
    $doc = bulkToolsDocument(Document::STATUS_PUBLISHED, ['slug' => 'budget-programme-2024']);
    $mine = LibraryRedirect::create(['from_path' => '/index.php?option=com_docman&id=12', 'to_path' => '/documents/budget-programme-2024', 'hits' => 37]);
    $foreign = LibraryRedirect::create(['from_path' => '/index.php?option=com_docman&id=13', 'to_path' => '/documents/budget-programme-2024-bis', 'hits' => 5]);
    $this->actingAs($this->librarian);

    $page = $this->livewire(LegacyRedirectsRelationManager::class, ['ownerRecord' => $doc, 'pageClass' => EditDocument::class])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertSee('/index.php?option=com_docman&amp;id=12', escape: false)
        ->assertSee('37')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');

    expect($page->instance()->isReadOnly())->toBeTrue()
        ->and(LegacyRedirectsRelationManager::canViewForRecord($doc, EditDocument::class))->toBeTrue();
});

it('ne montre pas les anciennes adresses au contributeur', function (): void {
    $contributor = bulkToolsUser('library_contributor');
    $own = bulkToolsDocument(Document::STATUS_DRAFT, ['uploaded_by' => $contributor->id]);
    $this->actingAs($contributor);

    expect(LegacyRedirectsRelationManager::canViewForRecord($own, EditDocument::class))->toBeFalse();
});

it('n\'envoie les e-mails en file qu\'après la validation de la transaction', function (): void {
    $job = new SendThrottledMail(['x@example.test'], new LibraryDocumentSubmittedMail('Titre', 'Mémoire', 'https://exemple.test'));

    expect($job->afterCommit)->toBeTrue();
});

it('affiche l\'historique et les anciennes adresses sous la fiche', function (): void {
    $doc = bulkToolsDocument(Document::STATUS_DRAFT);
    $contributor = bulkToolsUser('library_contributor');
    $own = bulkToolsDocument(Document::STATUS_DRAFT, ['uploaded_by' => $contributor->id]);

    $this->actingAs($this->librarian)
        ->get(DocumentResource::getUrl('edit', ['record' => $doc]))
        ->assertOk()
        ->assertSee('Historique')
        ->assertSee('Anciennes adresses')
        ->assertSee('Régénérer la couverture');

    $this->actingAs($contributor)
        ->get(DocumentResource::getUrl('edit', ['record' => $own]))
        ->assertOk()
        ->assertDontSee('Anciennes adresses')
        ->assertDontSee('Régénérer la couverture');

    expect(array_values($this->livewire(EditDocument::class, ['record' => $own->getRouteKey()])->instance()->getRelationManagers()))
        ->toBe([ActivitiesRelationManager::class]);
});

it('réindexe après un retrait de thèmes et après une soumission en lot', function (): void {
    $engine = bulkToolsRecordingEngine();
    $theme = MotCle::factory()->create();
    $published = bulkToolsDocument(Document::STATUS_PUBLISHED);
    $published->motsCles()->attach($theme);
    $draft = bulkToolsDocument(Document::STATUS_DRAFT);
    $engine->updated = [];
    $engine->deleted = [];
    $this->actingAs($this->librarian);

    bulkToolsList()->callTableBulkAction('retirerThemes', [$published], data: ['mots_cles' => [$theme->id]]);
    expect($engine->updated[$published->id]['mots_cles'] ?? null)->toBe([]);

    bulkToolsList()->callTableBulkAction('soumettreLot', [$draft]);
    expect($engine->deleted)->toContain($draft->id);
});

it('garde la couverture existante quand une régénération forcée ne produit rien', function (): void {
    Storage::fake('minio_documents');
    $doc = bulkToolsDocument(Document::STATUS_DRAFT);
    Storage::disk('minio_documents')->put($doc->file_path, '%PDF-1.4 contenu');
    $auto = "library/covers/{$doc->uuid}.jpg";
    Document::withoutEvents(fn () => $doc->update(['cover_path' => $auto]));
    $renderer = new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            return null;
        }
    };

    (new ProcessDocumentFile($doc->id, forceCover: true))->handle($renderer);

    expect($doc->fresh()->cover_path)->toBe($auto);
});

it('ne remplace pas une couverture personnalisée posée pendant le traitement automatique', function (): void {
    Storage::fake('minio_documents');
    Storage::fake('minio_media');
    $doc = bulkToolsDocument(Document::STATUS_DRAFT);
    Storage::disk('minio_documents')->put($doc->file_path, '%PDF-1.4 contenu');
    $custom = Document::customCoversDirectory().'/posee-entre-temps.jpg';
    $renderer = new class($doc->id, $custom) implements CoverRenderer
    {
        public function __construct(private readonly int $id, private readonly string $custom) {}

        public function render(string $pdfPath): ?string
        {
            // Un bibliothécaire dépose sa couverture pendant le rendu.
            DB::table('documents')->where('id', $this->id)->update(['cover_path' => $this->custom]);

            return 'JPEGDATA';
        }
    };

    (new ProcessDocumentFile($doc->id))->handle($renderer);

    expect($doc->fresh()->cover_path)->toBe($custom)
        ->and($doc->fresh()->file_checksum_md5)->toBe(md5('%PDF-1.4 contenu'));
});

it('versionne l\'URL publique de la couverture', function (): void {
    Storage::fake('minio_documents');
    Storage::fake('minio_media');
    $doc = bulkToolsDocument(Document::STATUS_PUBLISHED, ['slug' => 'couverture-versionnee', 'cover_path' => 'library/covers/x.jpg']);

    $url = (string) $this->getJson('/v1/library/documents/couverture-versionnee')->assertOk()->json('data.cover_url');

    expect($url)->toContain('library/covers/x.jpg?v='.$doc->fresh()->updated_at->getTimestamp())
        ->and(Document::factory()->make(['cover_path' => null])->coverUrl())->toBeNull();
});

it('ouvre la fiche en consultation à qui ne peut que la voir', function (): void {
    $viewer = bulkToolsUser('library_viewer');
    $doc = bulkToolsDocument(Document::STATUS_PUBLISHED);
    $this->actingAs($viewer);

    $this->get(DocumentResource::getUrl('view', ['record' => $doc]))->assertOk()
        ->assertSee($doc->getTranslation('title', 'fr'))
        ->assertSee('Historique')
        ->assertSee('Anciennes adresses');
    $this->get(DocumentResource::getUrl('edit', ['record' => $doc]))->assertForbidden();

    $page = $this->livewire(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->assertActionHidden('edit');

    expect(array_values($page->instance()->getRelationManagers()))
        ->toBe([ActivitiesRelationManager::class, LegacyRedirectsRelationManager::class])
        ->and(DocumentResource::recordUrl($doc))->toBe(DocumentResource::getUrl('view', ['record' => $doc]));

    bulkToolsList()->assertTableActionVisible('view', $doc)->assertTableActionHidden('edit', $doc);
});

it('ouvre la modification à qui peut modifier, la consultation pour un document en ligne sans droit de publier', function (): void {
    $draft = bulkToolsDocument(Document::STATUS_DRAFT);
    $published = bulkToolsDocument(Document::STATUS_PUBLISHED);

    $this->actingAs($this->librarian);
    expect(DocumentResource::recordUrl($draft))->toBe(DocumentResource::getUrl('edit', ['record' => $draft]));

    $this->actingAs(bulkToolsCataloguer());
    expect(DocumentResource::recordUrl($published))->toBe(DocumentResource::getUrl('view', ['record' => $published]));
    $this->get(DocumentResource::getUrl('view', ['record' => $published]))->assertOk();
});

it('laisse le contributeur consulter son dépôt soumis, pas celui d\'un autre', function (): void {
    $contributor = bulkToolsUser('library_contributor');
    $own = bulkToolsDocument(Document::STATUS_IN_REVIEW, ['uploaded_by' => $contributor->id]);
    $other = bulkToolsDocument(Document::STATUS_IN_REVIEW, ['uploaded_by' => bulkToolsUser('library_contributor')->id]);
    $this->actingAs($contributor);

    $this->get(DocumentResource::getUrl('view', ['record' => $own]))->assertOk()->assertDontSee('Anciennes adresses');
    $this->get(DocumentResource::getUrl('edit', ['record' => $own]))->assertForbidden();
    $this->get(DocumentResource::getUrl('view', ['record' => $other]))->assertNotFound();

    $page = $this->livewire(ViewDocument::class, ['record' => $own->getRouteKey()])->assertActionHidden('edit');

    expect(array_values($page->instance()->getRelationManagers()))->toBe([ActivitiesRelationManager::class])
        ->and(DocumentResource::recordUrl($own))->toBe(DocumentResource::getUrl('view', ['record' => $own]));
});

it('indexe la cible des redirections, de façon réversible', function (): void {
    $migration = require database_path('migrations/2026_10_19_210000_index_library_redirects_to_path.php');

    expect(Schema::hasIndex('library_redirects', ['to_path']))->toBeTrue();

    $migration->down();
    expect(Schema::hasIndex('library_redirects', ['to_path']))->toBeFalse();

    $migration->up();
    expect(Schema::hasIndex('library_redirects', ['to_path']))->toBeTrue();
});
