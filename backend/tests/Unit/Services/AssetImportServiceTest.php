<?php

declare(strict_types=1);

use App\Models\Asset;
use App\Services\AssetImportService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Filesystem\Filesystem;

beforeEach(function (): void {
    // Refresh DB for test isolation (Unit/Services dir doesn't have RefreshDatabase by default)
    Artisan::call('migrate:fresh');

    Storage::fake(AssetImportService::MEDIA_DISK);
    Storage::fake(AssetImportService::DOCUMENTS_DISK);

    $this->sourceDir = sys_get_temp_dir().'/asset-import-test-'.uniqid('', true);
    mkdir($this->sourceDir.'/logos/partenaires', 0755, true);
    mkdir($this->sourceDir.'/logos/institutions-coop', 0755, true);
    mkdir($this->sourceDir.'/conventions', 0755, true);
    mkdir($this->sourceDir.'/photos/direction', 0755, true);
    mkdir($this->sourceDir.'/slidershow', 0755, true);

    // Sample SVG (logo)
    file_put_contents(
        $this->sourceDir.'/logos/pssfp.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect fill="#6B2FA0" width="100" height="100"/></svg>'
    );

    // Sample PNG logo partenaire
    file_put_contents(
        $this->sourceDir.'/logos/partenaires/minfi-logo.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')
    );

    // Sample PDF convention
    file_put_contents(
        $this->sourceDir.'/conventions/convention-tripartite-2013.pdf',
        '%PDF-1.4 dummy content'
    );

    // Sprint S5.3 — Sample JPG slidershow (1×1 PNG renamed for mapping test).
    // Content irrelevant : on teste ici uniquement le mapping path → category.
    file_put_contents(
        $this->sourceDir.'/slidershow/slidershow1_pssfp1.jpg',
        base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
        ),
    );
});

afterEach(function (): void {
    if (isset($this->sourceDir) && is_dir($this->sourceDir)) {
        Filesystem::class;
        $files = (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ));
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($this->sourceDir);
    }
});

it('imports SVG logo into logos/ on minio_media disk', function (): void {
    $service = new AssetImportService($this->sourceDir);
    $report = $service->run();

    expect($report['imported'])->toBeGreaterThanOrEqual(2)
        ->and($report['errors'])->toBe(0);

    $logo = Asset::logos()->where('filename', 'pssfp.svg')->first();
    expect($logo)->not->toBeNull()
        ->and($logo->subcategory)->toBe('pssfp')
        ->and($logo->disk)->toBe(AssetImportService::MEDIA_DISK)
        ->and($logo->path)->toBe('logos/pssfp.svg')
        ->and($logo->mime)->toBe('image/svg+xml');

    Storage::disk(AssetImportService::MEDIA_DISK)->assertExists('logos/pssfp.svg');
});

it('imports partner PNG logo with extracted partner tag', function (): void {
    $service = new AssetImportService($this->sourceDir);
    $service->run();

    $logo = Asset::logos()->bySubcategory('partenaire')->first();
    expect($logo)->not->toBeNull()
        ->and($logo->tags)->toContain('minfi')
        ->and($logo->disk)->toBe(AssetImportService::MEDIA_DISK)
        ->and(str_starts_with($logo->path, 'logos/partenaires/'))->toBeTrue();
});

it('imports PDF convention into minio_documents disk', function (): void {
    $service = new AssetImportService($this->sourceDir);
    $service->run();

    $doc = Asset::documents()->bySubcategory('convention')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->mime)->toBe('application/pdf')
        ->and($doc->disk)->toBe(AssetImportService::DOCUMENTS_DISK);

    Storage::disk(AssetImportService::DOCUMENTS_DISK)->assertExists($doc->path);
});

it('is idempotent: re-running skips already imported files', function (): void {
    $service = new AssetImportService($this->sourceDir);
    $first = $service->run();
    expect($first['imported'])->toBeGreaterThan(0);

    $second = (new AssetImportService($this->sourceDir))->run();
    expect($second['imported'])->toBe(0)
        ->and($second['skipped'])->toBe($first['imported']);
});

it('imports slidershow JPG into photos/slidershow with slidershow tag (S5.3)', function (): void {
    $service = new AssetImportService($this->sourceDir);
    $service->run();

    $slidershow = Asset::query()
        ->where('subcategory', 'slidershow')
        ->first();

    expect($slidershow)->not->toBeNull()
        ->and($slidershow->category)->toBe(Asset::CATEGORY_PHOTO)
        ->and($slidershow->disk)->toBe(AssetImportService::MEDIA_DISK)
        ->and($slidershow->tags)->toContain('slidershow')
        ->and(str_starts_with($slidershow->path, 'photos/slidershow/'))->toBeTrue();
});

it('returns null mapping for unrelated files (skipped, no error)', function (): void {
    file_put_contents($this->sourceDir.'/divers-orphan.txt', 'not an asset');

    $service = new AssetImportService($this->sourceDir);
    $report = $service->run();

    // .txt is not in the Finder name regex, so it's not even collected — count unchanged
    expect($report['errors'])->toBe(0);
});

it('never imports PDFs from past campaign folders (real candidate PII), only visuals (LOT D)', function (): void {
    mkdir($this->sourceDir.'/textes/Appel a Candidature 12eme promo', 0755, true);
    // Simule les documents sensibles réels (listes nominatives, codes d'accès).
    file_put_contents(
        $this->sourceDir.'/textes/Appel a Candidature 12eme promo/Liste des candidats retenus PROMO 12 + MATRICULE.pdf',
        '%PDF-1.4 sensitive'
    );
    file_put_contents(
        $this->sourceDir.'/textes/Appel a Candidature 12eme promo/CODES_ACCES_PROMO12_complet.pdf',
        '%PDF-1.4 sensitive'
    );
    // Un visuel de la même campagne : lui doit passer.
    file_put_contents(
        $this->sourceDir.'/textes/Appel a Candidature 12eme promo/affiche.jpg',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')
    );

    $report = (new AssetImportService($this->sourceDir))->run();

    expect(Asset::query()->where('subcategory', 'appel-candidature')->count())->toBe(0);
    expect(Storage::disk(AssetImportService::DOCUMENTS_DISK)->allFiles('documents/appels-candidature'))->toBe([]);

    $visuel = Asset::query()->where('subcategory', 'evenements')->first();
    expect($visuel)->not->toBeNull()
        ->and($visuel->tags)->toContain('promo-12')
        ->and($visuel->disk)->toBe(AssetImportService::MEDIA_DISK);
});
