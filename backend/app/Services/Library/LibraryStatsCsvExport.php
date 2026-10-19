<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentDownload;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Export CSV agrégé des statistiques : aucune donnée personnelle (ni IP, ni navigateur, ni compte). */
class LibraryStatsCsvExport
{
    public const HEADERS = ['Titre', 'Type', 'Statut', 'Vues', 'Téléchargements (30 j)', 'Téléchargements (total)', 'Date de publication'];

    public static function fileName(): string
    {
        return 'bibliotheque-statistiques-'.now()->toDateString().'.csv';
    }

    public const PERMISSION = 'library.stats_export';

    /**
     * L'autorisation est vérifiée ici aussi, et pas seulement par l'action
     * de l'écran : le service ne doit rien produire pour un appelant qui
     * n'a pas le droit d'exporter, d'où qu'il soit appelé.
     */
    public function download(): StreamedResponse
    {
        abort_unless((bool) auth()->user()?->can(self::PERMISSION), 403);

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS, ';');

            Document::query()
                ->select('documents.*')
                ->selectSub(
                    DocumentDownload::query()->selectRaw('COUNT(*)')
                        ->whereColumn('document_downloads.document_id', 'documents.id')
                        ->where('downloaded_at', '>=', now()->subDays(30)),
                    'downloads_30d'
                )
                ->chunkById(500, function ($documents) use ($out): void {
                    foreach ($documents as $d) {
                        fputcsv($out, array_map([self::class, 'safe'], [
                            (string) ($d->getTranslation('title', 'fr', false) ?: $d->slug),
                            Document::TYPES[$d->type] ?? (string) $d->type,
                            DocumentResource::STATUS_LABELS[$d->status] ?? (string) $d->status,
                            (int) $d->views_count,
                            (int) $d->downloads_30d,
                            (int) $d->downloads_count,
                            $d->published_at?->format('Y-m-d') ?? '',
                        ]), ';');
                    }
                });
            fclose($out);
        }, self::fileName(), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise l'injection de formule dans un tableur. */
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
