<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Sprint S5.1 — exposition publique du catalogue Formation continue 2026.
 *
 * Le bucket `minio_documents` est privé mais ce fichier est explicitement
 * autorisé en téléchargement public (document marketing référencé depuis le
 * site institutionnel). Pas de filigrane (document non restreint).
 */
Route::get('/documents/catalogue-pssfp-2026.pdf', function () {
    $path = 'documents/catalogue-pssfp-2026.pdf';
    $disk = Storage::disk('minio_documents');

    if (! $disk->exists($path)) {
        abort(Response::HTTP_NOT_FOUND);
    }

    return response()->streamDownload(
        static function () use ($disk, $path): void {
            $stream = $disk->readStream($path);
            if ($stream === null) {
                return;
            }
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        },
        'PSSFP-Catalogue-Formation-Continue-2026.pdf',
        [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="PSSFP-Catalogue-Formation-Continue-2026.pdf"',
            'Cache-Control' => 'public, max-age=3600',
        ],
    );
})->name('public.catalogue.formation-continue');
