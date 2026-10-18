<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Services\Library\AccessDecision;
use App\Services\Library\DocumentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Téléchargement contrôlé d'un document (spec V1 §5.2).
 *
 * Pas de Storage::exists() : la clé MinIO de prod n'a pas HeadObject.
 * L'existence du fichier se lit dans `file_path`.
 */
final class DownloadController extends Controller
{
    public function __invoke(Request $request, string $uuid, DocumentAccess $access): RedirectResponse|JsonResponse
    {
        $document = Str::isUuid($uuid) ? Document::query()->where('uuid', $uuid)->first() : null;

        if ($document === null || ! $document->isPublished()) {
            return $this->notFound();
        }

        $decision = $access->decide($request->user(), $document);

        if (! $decision->allowed) {
            return response()->json([
                'message' => 'Accès non disponible pour ce document.',
                'reason' => $decision->reason,
            ], Response::HTTP_FORBIDDEN);
        }

        if ($decision->reason === AccessDecision::EXTERNAL) {
            // Jamais de redirection vers javascript:, data:, ftp:…
            $scheme = strtolower((string) parse_url((string) $document->external_url, PHP_URL_SCHEME));

            return in_array($scheme, ['http', 'https'], true)
                ? redirect()->away((string) $document->external_url)
                : $this->notFound();
        }

        if ($document->file_path === null) {
            return $this->notFound();
        }

        $url = Storage::disk((string) config('library.documents_disk'))->temporaryUrl(
            $document->file_path,
            now()->addMinutes((int) config('library.signed_url_minutes')),
            ['ResponseContentDisposition' => 'inline; filename="'.$document->slug.'.pdf"'],
        );

        $this->record($request, $document);

        return redirect()->away($url);
    }

    private function record(Request $request, Document $document): void
    {
        DB::transaction(function () use ($request, $document): void {
            DocumentDownload::create([
                'document_id' => $document->id,
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'downloaded_at' => now(),
            ]);

            DB::table('documents')->where('id', $document->id)->increment('downloads_count');
        });
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Document introuvable.'], Response::HTTP_NOT_FOUND);
    }
}
