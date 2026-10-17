<?php

declare(strict_types=1);

use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Services\Library\Files\CoverRenderer;
use App\Services\Library\Files\PdftoppmCoverRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses()->group('module-3', 'library');

beforeEach(function (): void {
    Storage::fake('minio_documents');
    Storage::fake('minio_media');
});

it('calcule taille et MD5 et enregistre la couverture', function (): void {
    Storage::disk('minio_documents')->put('documents/a.pdf', '%PDF-1.4 contenu');
    $doc = Document::factory()->create();
    Document::withoutEvents(fn () => $doc->update(['file_path' => 'documents/a.pdf']));
    app()->instance(CoverRenderer::class, new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            return file_exists($pdfPath) ? 'JPEGDATA' : null;
        }
    });

    (new ProcessDocumentFile($doc->id))->handle(app(CoverRenderer::class));

    $doc->refresh();
    expect($doc->file_checksum_md5)->toBe(md5('%PDF-1.4 contenu'))
        ->and($doc->file_size_bytes)->toBe(strlen('%PDF-1.4 contenu'))
        ->and($doc->cover_path)->toBe("library/covers/{$doc->uuid}.jpg");
    Storage::disk('minio_media')->assertExists("library/covers/{$doc->uuid}.jpg");
});

it('laisse la couverture vide si le rendu échoue', function (): void {
    Storage::disk('minio_documents')->put('documents/b.pdf', 'pas un pdf');
    $doc = Document::factory()->create();
    Document::withoutEvents(fn () => $doc->update(['file_path' => 'documents/b.pdf']));
    app()->instance(CoverRenderer::class, new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            return null;
        }
    });

    (new ProcessDocumentFile($doc->id))->handle(app(CoverRenderer::class));

    expect($doc->fresh()->cover_path)->toBeNull()->and($doc->fresh()->file_checksum_md5)->toBe(md5('pas un pdf'));
});

it('déclenche le traitement quand le fichier change', function (): void {
    Bus::fake([ProcessDocumentFile::class]);
    $doc = Document::factory()->create();

    $doc->update(['abstract' => ['fr' => 'nouveau résumé']]);
    Bus::assertNotDispatched(ProcessDocumentFile::class);

    $doc->update(['file_path' => 'documents/c.pdf']);
    Bus::assertDispatched(ProcessDocumentFile::class, fn (ProcessDocumentFile $job) => $job->documentId === $doc->id);
});

it('rend la première page d\'un vrai PDF avec pdftoppm', function (): void {
    $pdf = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($pdf, Pdf::loadHTML('<h1>Couverture</h1>')->output());

    $jpeg = (new PdftoppmCoverRenderer)->render($pdf);

    expect($jpeg)->not->toBeNull()->and(substr((string) $jpeg, 0, 3))->toBe("\xFF\xD8\xFF");
    unlink($pdf);
})->skip(fn () => trim((string) shell_exec('command -v pdftoppm')) === '', 'pdftoppm absent');
