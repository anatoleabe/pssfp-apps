<?php

declare(strict_types=1);

use App\Filament\Resources\AssetResource\Pages\CreateAsset;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;

/*
 * Champ fichier sur disque privé (PDF de la bibliothèque) : Filament signe
 * par défaut n'importe quel chemin placé dans l'état du champ. Un compte du
 * panneau pouvait ainsi forger `data.file_path` vers le PDF réservé ou payant
 * d'un autre document, en obtenir une URL signée (getFormUploadedFiles) ou
 * l'attacher à sa propre fiche. Le champ n'accepte désormais que le fichier
 * déjà enregistré sur la fiche, ou un nouveau dépôt.
 */
uses()->group('filament', 'module-3', 'library');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Bus::fake([ProcessDocumentFile::class]);
    Storage::fake('minio_documents');
    $this->seed([RolePermissionSeeder::class]);

    // Cible : PDF réservé et payant d'un document publié par la bibliothèque.
    $this->target = Document::factory()->published()->withFile()->create(['access_level' => 'librarian', 'price_xaf' => 5000]);
    Storage::disk('minio_documents')->put($this->target->file_path, '%PDF-1.4 secret');
});

function privateUploadUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/** @return array<string, mixed> */
function privateUploadForm(array $overrides = []): array
{
    return [
        'type' => 'memoire',
        'title.fr' => 'Dépôt avec fichier',
        'slug' => 'depot-avec-fichier',
        'language' => 'fr',
        'access_level' => 'public',
        ...$overrides,
    ];
}

/** URLs que le champ délivrerait au navigateur pour son état courant. */
function privateUploadUrls(Testable $page): array
{
    return array_values($page->instance()->getFormUploadedFiles('data.file_path') ?? []);
}

it('ne signe pas un chemin forgé à la création', function (string $role): void {
    $this->actingAs(privateUploadUser($role));

    $page = $this->livewire(CreateDocument::class)->set('data.file_path', ['x' => $this->target->file_path]);

    expect(privateUploadUrls($page))->each->toBeNull();
})->with(['library_contributor', 'librarian']);

it('ne signe pas un chemin forgé sur une fiche existante', function (string $role): void {
    $user = privateUploadUser($role);
    $own = Document::factory()->withFile()->create(['uploaded_by' => $user->id]);
    $this->actingAs($user);

    $page = $this->livewire(EditDocument::class, ['record' => $own->getRouteKey()])
        ->set('data.file_path', ['x' => $this->target->file_path]);

    expect(privateUploadUrls($page))->each->toBeNull();
})->with(['library_contributor', 'librarian']);

it('refuse d\'enregistrer un chemin forgé à la création', function (string $role): void {
    $this->actingAs(privateUploadUser($role));

    $this->livewire(CreateDocument::class)
        ->fillForm(privateUploadForm())
        ->set('data.file_path', ['x' => $this->target->file_path])
        ->call('create')
        ->assertHasFormErrors(['file_path']);

    expect(Document::query()->where('slug', 'depot-avec-fichier')->exists())->toBeFalse();
})->with(['library_contributor', 'librarian']);

it('refuse d\'enregistrer un chemin forgé sur une fiche existante', function (string $role): void {
    $user = privateUploadUser($role);
    $own = Document::factory()->create(['uploaded_by' => $user->id]);
    $this->actingAs($user);

    $this->livewire(EditDocument::class, ['record' => $own->getRouteKey()])
        ->set('data.file_path', ['x' => $this->target->file_path])
        ->call('save')
        ->assertHasFormErrors(['file_path']);

    expect($own->fresh()->file_path)->toBeNull();
})->with(['library_contributor', 'librarian']);

it('montre le fichier déjà enregistré et le conserve à l\'enregistrement', function (string $role): void {
    $user = privateUploadUser($role);
    $own = Document::factory()->withFile()->create(['uploaded_by' => $user->id, 'year' => 2020]);
    $this->actingAs($user);

    $page = $this->livewire(EditDocument::class, ['record' => $own->getRouteKey()]);

    expect(privateUploadUrls($page))->toHaveCount(1)
        ->and(privateUploadUrls($page)[0]['url'] ?? null)->toBeString();

    $page->fillForm(['year' => 2024])->call('save')->assertHasNoFormErrors();

    expect($own->fresh()->file_path)->toBe($own->file_path)
        ->and($own->fresh()->year)->toBe(2024);
})->with(['library_contributor', 'librarian']);

it('accepte un nouveau PDF déposé', function (string $role): void {
    $user = privateUploadUser($role);
    $this->actingAs($user);

    $this->livewire(CreateDocument::class)
        ->fillForm(privateUploadForm())
        ->set('data.file_path', [UploadedFile::fake()->createWithContent('memoire.pdf', "%PDF-1.4\n%%EOF\n")])
        ->call('create')
        ->assertHasNoFormErrors();

    $doc = Document::query()->where('slug', 'depot-avec-fichier')->firstOrFail();

    expect($doc->file_path)->toStartWith('documents/')
        ->and($doc->file_path)->not->toBe($this->target->file_path)
        ->and($doc->uploaded_by)->toBe($user->id);
    Storage::disk('minio_documents')->assertExists($doc->file_path);
})->with(['library_contributor', 'librarian']);

it('ne signe pas non plus un chemin forgé dans le champ de dépôt de la médiathèque', function (): void {
    $this->actingAs(privateUploadUser('editor'));

    $page = $this->livewire(CreateAsset::class)
        ->set('data.category', 'document')
        ->set('data.upload', ['x' => $this->target->file_path]);

    expect(array_values($page->instance()->getFormUploadedFiles('data.upload') ?? []))->each->toBeNull();
});
