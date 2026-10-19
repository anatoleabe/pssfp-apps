<?php

declare(strict_types=1);

use App\Filament\Resources\AssetResource\Pages\CreateAsset;
use App\Filament\Resources\AssetResource\Pages\EditAsset;
use App\Filament\Resources\AssetResource\Pages\ListAssets;
use App\Models\Asset;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Médiathèque (Module 1) : le disque et le chemin d'un média ne se saisissent
 * plus. Le disque découle de la catégorie (liste fermée), le chemin du
 * fichier déposé. Avant, un éditeur pouvait taper `minio_candidatures` et
 * n'importe quel chemin, puis « Ouvrir » lui signait une URL vers une pièce
 * de candidature ou un PDF réservé de la bibliothèque.
 */
uses()->group('filament', 'module-1');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Storage::fake('minio_media');
    // Comme le bucket privé de prod : un fichier n'est public que s'il est déposé en public.
    Storage::fake('minio_documents', ['visibility' => 'private']);
    Storage::fake('minio_candidatures');
    Storage::disk('minio_documents')->buildTemporaryUrlsUsing(fn (string $path): string => 'https://signe.test/'.$path);
    $this->seed([RolePermissionSeeder::class]);

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
    $this->actingAs($this->editor);
});

/** @param  array<string, mixed>  $attributes */
function assetTestRecord(array $attributes = []): Asset
{
    return Asset::create([
        'filename' => 'logo-pssfp.png',
        'original_filename' => 'logo-pssfp.png',
        'category' => Asset::CATEGORY_LOGO,
        'subcategory' => 'pssfp',
        'mime' => 'image/png',
        'size' => 1200,
        'disk' => 'minio_media',
        'path' => 'logos/pssfp/logo-pssfp.png',
        ...$attributes,
    ]);
}

/** Ligne héritée d'avant le correctif : disque hors liste, insérée sans passer par le modèle. */
function assetTestLegacyRow(string $disk, string $path): Asset
{
    $uuid = (string) Str::uuid();
    DB::table('assets')->insert([
        'uuid' => $uuid, 'filename' => 'piece.pdf', 'original_filename' => 'piece.pdf', 'category' => Asset::CATEGORY_DOCUMENT, 'subcategory' => 'autre',
        'mime' => 'application/pdf', 'size' => 10, 'disk' => $disk, 'path' => $path,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return Asset::query()->where('uuid', $uuid)->firstOrFail();
}

it('ne propose ni disque ni chemin à saisir', function (): void {
    $this->livewire(CreateAsset::class)
        ->assertFormFieldDoesNotExist('disk')
        ->assertFormFieldDoesNotExist('path');

    $this->livewire(EditAsset::class, ['record' => assetTestRecord()->getRouteKey()])
        ->assertFormFieldDoesNotExist('disk')
        ->assertFormFieldDoesNotExist('path');
});

it('range le fichier déposé sur le disque de sa catégorie', function (string $category, string $subcategory, UploadedFile $file, string $disk, string $directory): void {
    $this->livewire(CreateAsset::class)
        ->fillForm(['category' => $category])
        ->fillForm(['subcategory' => $subcategory])
        ->set('data.upload', [$file])
        ->call('create')
        ->assertHasNoFormErrors();

    $asset = Asset::query()->firstOrFail();

    expect($asset->disk)->toBe($disk)
        ->and($asset->path)->toStartWith($directory)
        ->and($asset->original_filename)->toBe($file->getClientOriginalName())
        ->and($asset->size)->toBeGreaterThan(0)
        ->and($asset->uploaded_by)->toBe($this->editor->id);
    Storage::disk($disk)->assertExists($asset->path);
})->with([
    'photo' => fn () => [Asset::CATEGORY_PHOTO, 'campus', UploadedFile::fake()->image('campus.png', 20, 20), 'minio_media', 'photos/campus/'],
    'document' => fn () => [Asset::CATEGORY_DOCUMENT, 'convention', UploadedFile::fake()->createWithContent('convention.pdf', "%PDF-1.4\n%%EOF\n"), 'minio_documents', 'documents/convention/'],
]);

it('ignore un disque et un chemin forgés à la création', function (): void {
    $this->livewire(CreateAsset::class)
        ->fillForm(['category' => Asset::CATEGORY_DOCUMENT])
        ->fillForm(['subcategory' => 'convention'])
        ->set('data.upload', [UploadedFile::fake()->createWithContent('convention.pdf', "%PDF-1.4\n%%EOF\n")])
        ->set('data.disk', 'minio_candidatures')
        ->set('data.path', 'candidatures/2026/piece-identite.pdf')
        ->call('create')
        ->assertHasNoFormErrors();

    $asset = Asset::query()->firstOrFail();

    expect($asset->disk)->toBe('minio_documents')
        ->and($asset->path)->toStartWith('documents/convention/');
});

it('exige un fichier déposé à la création', function (): void {
    $this->livewire(CreateAsset::class)
        ->fillForm(['category' => Asset::CATEGORY_PHOTO])
        ->fillForm(['subcategory' => 'campus', 'filename' => 'x.png', 'mime' => 'image/png', 'size' => 10])
        ->set('data.disk', 'minio_candidatures')
        ->set('data.path', 'candidatures/2026/piece-identite.pdf')
        ->call('create')
        ->assertHasFormErrors(['upload']);

    expect(Asset::query()->count())->toBe(0);
});

it('garde disque, chemin et catégorie à l\'édition, même sur une requête forgée', function (): void {
    $asset = assetTestRecord();

    $this->livewire(EditAsset::class, ['record' => $asset->getRouteKey()])
        ->set('data.disk', 'minio_candidatures')
        ->set('data.path', 'candidatures/2026/piece-identite.pdf')
        ->set('data.category', Asset::CATEGORY_DOCUMENT)
        ->fillForm(['alt.fr' => 'Logo du PSSFP'])
        ->call('save')
        ->assertHasNoFormErrors();

    $asset->refresh();
    expect($asset->disk)->toBe('minio_media')
        ->and($asset->path)->toBe('logos/pssfp/logo-pssfp.png')
        ->and($asset->category)->toBe(Asset::CATEGORY_LOGO)
        ->and($asset->getTranslation('alt', 'fr'))->toBe('Logo du PSSFP');
});

it('refuse au niveau du modèle un disque hors de la liste', function (): void {
    expect(fn () => assetTestRecord(['disk' => 'minio_candidatures']))->toThrow(InvalidArgumentException::class);

    $asset = assetTestRecord();
    expect(fn () => $asset->update(['disk' => 'minio_candidatures']))->toThrow(InvalidArgumentException::class);
    expect($asset->fresh()->disk)->toBe('minio_media');
});

it('ouvre un média public par son URL publique et un document par une URL signée de son propre chemin', function (): void {
    $logo = assetTestRecord();
    $doc = assetTestRecord([
        'filename' => 'convention.pdf', 'category' => Asset::CATEGORY_DOCUMENT, 'subcategory' => 'convention',
        'mime' => 'application/pdf', 'disk' => 'minio_documents', 'path' => 'conventions/convention-uy2.pdf',
    ]);

    $this->livewire(ListAssets::class)
        ->assertTableActionHasUrl('open', Storage::disk('minio_media')->url('logos/pssfp/logo-pssfp.png'), $logo)
        ->assertTableActionHasUrl('open', 'https://signe.test/conventions/convention-uy2.pdf', $doc);
});

it('ne signe rien pour une ligne héritée hors de la liste des disques, sans la supprimer', function (): void {
    $legacy = assetTestLegacyRow('minio_candidatures', 'candidatures/2026/piece-identite.pdf');

    $this->livewire(ListAssets::class)
        ->assertCanSeeTableRecords([$legacy])
        ->assertTableActionHidden('open', $legacy);

    expect($legacy->openUrl())->toBeNull()
        ->and($legacy->signedUrl())->toBeNull();

    // Ses métadonnées restent modifiables, son disque ne bouge pas.
    $this->livewire(EditAsset::class, ['record' => $legacy->getRouteKey()])
        ->fillForm(['alt.fr' => 'Pièce'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($legacy->fresh()->disk)->toBe('minio_candidatures');
});

it('dépose un document en privé et un média en public', function (string $category, string $subcategory, UploadedFile $file, string $disk, string $visibility): void {
    $this->livewire(CreateAsset::class)
        ->fillForm(['category' => $category])
        ->fillForm(['subcategory' => $subcategory])
        ->set('data.upload', [$file])
        ->call('create')
        ->assertHasNoFormErrors();

    $asset = Asset::query()->firstOrFail();

    expect(Storage::disk($disk)->getVisibility($asset->path))->toBe($visibility);
})->with([
    'photo' => fn () => [Asset::CATEGORY_PHOTO, 'campus', UploadedFile::fake()->image('campus.png', 20, 20), 'minio_media', 'public'],
    'document' => fn () => [Asset::CATEGORY_DOCUMENT, 'convention', UploadedFile::fake()->createWithContent('convention.pdf', "%PDF-1.4\n%%EOF\n"), 'minio_documents', 'private'],
]);

it('refuse une catégorie inconnue ou une sous-catégorie d\'une autre catégorie, sans rien stocker', function (array $state, array $errors): void {
    $page = $this->livewire(CreateAsset::class);

    foreach ($state as $path => $value) {
        $page->set("data.{$path}", $value);
    }

    $page->set('data.upload', [UploadedFile::fake()->createWithContent('convention.pdf', "%PDF-1.4\n%%EOF\n")])
        ->call('create')
        ->assertHasFormErrors($errors);

    expect(Asset::query()->count())->toBe(0)
        ->and(Storage::disk('minio_media')->allFiles())->toBe([])
        ->and(Storage::disk('minio_documents')->allFiles())->toBe([]);
})->with([
    'catégorie inconnue' => [['category' => 'inconnue', 'subcategory' => 'autre'], ['category']],
    'sous-catégorie d\'une autre catégorie' => [['category' => Asset::CATEGORY_PHOTO, 'subcategory' => 'convention'], ['subcategory']],
]);
