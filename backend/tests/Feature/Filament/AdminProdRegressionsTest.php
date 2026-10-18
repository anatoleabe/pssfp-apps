<?php

declare(strict_types=1);

use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\CampagneCandidatureResource\Pages\EditCampagneCandidature;
use App\Filament\Resources\CampagneCandidatureResource\Pages\ListCampagneCandidatures;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Models\Article;
use App\Models\CampagneCandidature;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;

uses()->group('filament');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([RolePermissionSeeder::class]);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');
});

/*
 * Les colonnes traduisibles (`title.fr`) passaient par data_get sur la
 * valeur déjà traduite (une chaîne) : la cellule restait vide.
 */
it('affiche le titre français des articles dans la liste', function (): void {
    $article = Article::factory()->create(['title' => ['fr' => 'Rentrée solennelle de la promotion fictive']]);
    $this->actingAs($this->superAdmin);

    $this->livewire(ListArticles::class)
        ->assertTableColumnStateSet('title.fr', 'Rentrée solennelle de la promotion fictive', $article)
        ->searchTable('promotion fictive')
        ->assertCanSeeTableRecords([$article]);
});

it('affiche le titre français des pages et le nom des campagnes dans les listes', function (): void {
    $page = Page::factory()->create(['title' => ['fr' => 'Mot du coordonnateur fictif']]);
    $campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Promotion fictive 2030', 'en' => 'Fictional intake 2030']]);
    $this->actingAs($this->superAdmin);

    $this->livewire(ListPages::class)
        ->assertTableColumnStateSet('title.fr', 'Mot du coordonnateur fictif', $page);
    $this->livewire(ListCampagneCandidatures::class)
        ->assertTableColumnStateSet('nom.fr', 'Promotion fictive 2030', $campagne);
});

/*
 * En prod, le disque par défaut est MinIO (sans HeadObject). Les fichiers
 * temporaires de Livewire y transitaient : Filament ne pouvait pas vérifier
 * leur existence et enregistrait `null` — un fichier orphelin est resté dans
 * `livewire-tmp` sur MinIO (constaté le 2026-10-18), aucune campagne n'avait
 * de communiqué. Le harnais de test Livewire ne passe pas par ce disque :
 * on vérifie donc la configuration elle-même.
 */
it('fait transiter les envois Livewire par le disque local, jamais par MinIO', function (): void {
    config()->set('filesystems.default', 'minio');

    expect(config('livewire.temporary_file_upload.disk'))->toBe('local')
        ->and(config('filesystems.disks.local.driver'))->toBe('local');
});

/*
 * En prod, la clé MinIO n'a pas HeadObject : `exists()` échoue toujours.
 * Le communiqué déjà déposé ne doit pas disparaître quand on enregistre
 * la campagne pour une autre raison.
 */
it('conserve le communiqué PDF à l\'édition quand le disque ne sait pas vérifier son existence', function (Closure $disk): void {
    $disk();
    $campagne = CampagneCandidature::factory()->create([
        'communique_pdf_path' => 'communiques/appel-candidature-test-fr.pdf',
        'communique_pdf_path_en' => 'communiques/appel-candidature-test-en.pdf',
        'max_voeux' => 1,
    ]);
    $this->actingAs($this->superAdmin);

    $this->livewire(EditCampagneCandidature::class, ['record' => $campagne->getRouteKey()])
        ->fillForm(['max_voeux' => 2])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $campagne->fresh();
    expect($fresh->max_voeux)->toBe(2)
        ->and($fresh->communique_pdf_path)->toBe('communiques/appel-candidature-test-fr.pdf')
        ->and($fresh->communique_pdf_path_en)->toBe('communiques/appel-candidature-test-en.pdf');
})->with([
    'exists() renvoie false' => [fn () => Storage::fake(CampagneCandidature::COMMUNIQUE_DISK)],
    'exists() lève une exception (HeadObject refusé)' => [function (): void {
        $fake = Storage::fake(CampagneCandidature::COMMUNIQUE_DISK);
        $disk = Mockery::mock(FilesystemAdapter::class, [$fake->getDriver(), $fake->getAdapter(), $fake->getConfig()])->makePartial();
        $disk->shouldReceive('exists', 'fileExists', 'size', 'mimeType')
            ->andThrow(UnableToCheckFileExistence::forLocation('communiques/x.pdf'));
        Storage::set(CampagneCandidature::COMMUNIQUE_DISK, $disk);
    }],
]);
