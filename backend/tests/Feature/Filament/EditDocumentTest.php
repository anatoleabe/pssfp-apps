<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;

uses()->group('filament', 'module-3');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Bus::fake([ProcessDocumentFile::class]);
    $this->seed([RolePermissionSeeder::class]);

    $this->librarian = User::factory()->create();
    $this->librarian->assignRole('librarian');
});

it('modifie un champ de la notice', function (): void {
    Storage::fake('minio_documents');
    $doc = Document::factory()->create(['year' => 2020]);
    $this->actingAs($this->librarian);

    $this->livewire(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->fillForm(['year' => 2023, 'journal' => 'Revue fictive'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($doc->fresh()->year)->toBe(2023)
        ->and($doc->fresh()->journal)->toBe('Revue fictive');
});

/*
 * En prod, la clé MinIO n'a pas HeadObject : `exists()` échoue toujours.
 * Le PDF déjà enregistré ne doit pas disparaître à la sauvegarde d'un autre champ.
 */
it('conserve le PDF à l\'édition quand le disque ne sait pas vérifier son existence', function (Closure $disk): void {
    $disk();
    $doc = Document::factory()->withFile()->create(['year' => 2020]);
    $path = $doc->file_path;
    $this->actingAs($this->librarian);

    $this->livewire(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->fillForm(['year' => 2024])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($doc->fresh()->file_path)->toBe($path)
        ->and($doc->fresh()->year)->toBe(2024);
})->with([
    'exists() renvoie false' => [fn () => Storage::fake('minio_documents')],
    'exists() lève une exception (HeadObject refusé)' => [function (): void {
        $fake = Storage::fake('minio_documents');
        $disk = Mockery::mock(FilesystemAdapter::class, [$fake->getDriver(), $fake->getAdapter(), $fake->getConfig()])->makePartial();
        $disk->shouldReceive('exists', 'fileExists', 'size', 'mimeType')
            ->andThrow(UnableToCheckFileExistence::forLocation('documents/x.pdf'));
        Storage::set('minio_documents', $disk);
    }],
]);
