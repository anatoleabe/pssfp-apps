<?php

declare(strict_types=1);

use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Services\Library\Files\CoverRenderer;
use App\Services\Library\Files\PdftoppmCoverRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

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

it('n\'écrit ni taille ni MD5 quand le PDF est illisible', function (): void {
    $doc = Document::factory()->create();
    Document::withoutEvents(fn () => $doc->update(['file_path' => 'documents/absent.pdf']));

    (new ProcessDocumentFile($doc->id))->handle(app(CoverRenderer::class));

    expect($doc->fresh()->file_checksum_md5)->toBeNull()->and($doc->fresh()->file_size_bytes)->toBeNull();
});

it('déclenche une seule fois à la création avec un fichier', function (): void {
    Bus::fake([ProcessDocumentFile::class]);

    Document::factory()->create(['file_path' => 'documents/d.pdf']);

    Bus::assertDispatchedTimes(ProcessDocumentFile::class, 1);
});

it('ne redéclenche pas pour une édition étrangère au fichier après création', function (): void {
    Bus::fake([ProcessDocumentFile::class]);
    $doc = Document::factory()->create(['file_path' => 'documents/e.pdf']);

    $doc->update(['abstract' => ['fr' => 'autre résumé']]);

    Bus::assertDispatchedTimes(ProcessDocumentFile::class, 1);
});

it('stocke taille et MD5 et vide la couverture quand le rendu expire', function (): void {
    Storage::disk('minio_documents')->put('documents/f.pdf', 'contenu f');
    $doc = Document::factory()->create();
    Document::withoutEvents(fn () => $doc->update(['file_path' => 'documents/f.pdf', 'cover_path' => 'library/covers/ancienne.jpg']));
    Process::fake(['*' => fn () => throw new ProcessTimedOutException(new SymfonyProcess(['pdftoppm']), 1)]);

    (new ProcessDocumentFile($doc->id))->handle(new PdftoppmCoverRenderer);

    $doc->refresh();
    expect($doc->file_checksum_md5)->toBe(md5('contenu f'))->and($doc->cover_path)->toBeNull();
});

it('supprime le fichier temporaire après le traitement', function (): void {
    Storage::disk('minio_documents')->put('documents/g.pdf', 'contenu g');
    $doc = Document::factory()->create();
    Document::withoutEvents(fn () => $doc->update(['file_path' => 'documents/g.pdf']));
    $avant = glob(sys_get_temp_dir().'/doc*') ?: [];

    (new ProcessDocumentFile($doc->id))->handle(new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            return null;
        }
    });

    expect(array_diff(glob(sys_get_temp_dir().'/doc*') ?: [], $avant))->toBe([]);
});

it('supprime le fichier temporaire même si le rendu lève une exception', function (): void {
    Storage::disk('minio_documents')->put('documents/h.pdf', 'contenu h');
    $doc = Document::factory()->create();
    Document::withoutEvents(fn () => $doc->update(['file_path' => 'documents/h.pdf']));
    $avant = glob(sys_get_temp_dir().'/doc*') ?: [];

    expect(fn () => (new ProcessDocumentFile($doc->id))->handle(new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            throw new RuntimeException('boom');
        }
    }))->toThrow(RuntimeException::class);

    expect(array_diff(glob(sys_get_temp_dir().'/doc*') ?: [], $avant))->toBe([]);
});

it('journalise le code de sortie et la sortie d\'erreur tronquée de pdftoppm', function (): void {
    Process::fake(['*' => Process::result(output: '', errorOutput: str_repeat('Syntax Error: ', 100), exitCode: 99)]);
    Log::spy();

    expect((new PdftoppmCoverRenderer)->render('/tmp/inexistant.pdf'))->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context['exit_code'] === 99
            && str_starts_with($context['stderr'], 'Syntax Error')
            && mb_strlen($context['stderr']) <= 503
    );
});

it('journalise l\'échec du processus pdftoppm', function (): void {
    Process::fake(['*' => fn () => throw new ProcessTimedOutException(new SymfonyProcess(['pdftoppm']), 1)]);
    Log::spy();

    expect((new PdftoppmCoverRenderer)->render('/tmp/inexistant.pdf'))->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        // Laravel rehausse l'exception Symfony en Illuminate\Process\Exceptions\ProcessTimedOutException.
        fn (string $message, array $context): bool => $context['exception'] === Illuminate\Process\Exceptions\ProcessTimedOutException::class
    );
});

it('s\'arrête avant le délai de reprise de la file Redis', function (): void {
    expect((new ProcessDocumentFile(1))->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

it('garde la couverture précédente et journalise si l\'envoi de la nouvelle échoue', function (): void {
    Storage::disk('minio_documents')->put('documents/i.pdf', 'contenu i');
    $doc = Document::factory()->create();
    Document::withoutEvents(fn () => $doc->update(['file_path' => 'documents/i.pdf', 'cover_path' => 'library/covers/ancienne.jpg']));
    // Disque des couvertures en lecture seule (throw=false) : put() renvoie false.
    $root = sys_get_temp_dir().'/covers-ro-'.bin2hex(random_bytes(4));
    mkdir($root.'/library/covers', 0777, true);
    chmod($root.'/library/covers', 0500);
    Storage::set('minio_media', Storage::build(['driver' => 'local', 'root' => $root, 'throw' => false]));
    Log::spy();

    try {
        (new ProcessDocumentFile($doc->id))->handle(new class implements CoverRenderer
        {
            public function render(string $pdfPath): ?string
            {
                return 'JPEGDATA';
            }
        });
    } finally {
        chmod($root.'/library/covers', 0700);
        File::deleteDirectory($root);
    }

    $doc->refresh();
    expect($doc->cover_path)->toBe('library/covers/ancienne.jpg')
        ->and($doc->file_checksum_md5)->toBe(md5('contenu i'));
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'couverture'))->once();
});
