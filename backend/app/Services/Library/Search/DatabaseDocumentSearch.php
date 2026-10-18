<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

use App\Http\Controllers\Api\Library\DocumentController;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;

/**
 * Repli PostgreSQL (ADR-0003) : ILIKE insensible aux accents sur titre,
 * résumé et auteurs. Suffisant pour quelques milliers de documents.
 */
final class DatabaseDocumentSearch implements DocumentSearch
{
    public function search(string $q, array $filters, int $page, int $perPage): SearchResult
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::fold($q)).'%';

        $base = Document::query()->published()
            ->where(function (Builder $w) use ($like): void {
                $w->whereRaw("translate(lower(title->>'fr'), 'àâäéèêëîïôöùûüç', 'aaaeeeeiioouuuc') LIKE ?", [$like])
                    ->orWhereRaw("translate(lower(coalesce(abstract->>'fr', '')), 'àâäéèêëîïôöùûüç', 'aaaeeeeiioouuuc') LIKE ?", [$like])
                    ->orWhereHas('authors', fn (Builder $a) => $a->whereRaw("translate(lower(author_name), 'àâäéèêëîïôöùûüç', 'aaaeeeeiioouuuc') LIKE ?", [$like]));
            });

        DocumentController::applyFilters($base, $filters);

        $facetType = (clone $base)->selectRaw('type, COUNT(*) AS n')->groupBy('type')->pluck('n', 'type')
            ->map(fn ($n): int => (int) $n)->all();
        $facetAccess = (clone $base)->selectRaw(
            "CASE WHEN price_xaf IS NOT NULL THEN 'payant' WHEN access_level <> 'public' THEN 'reserve' ELSE 'libre' END AS mode, COUNT(*) AS n"
        )->groupBy('mode')->pluck('n', 'mode')->map(fn ($n): int => (int) $n)->all();

        $total = (clone $base)->count();
        $documents = $base->with(['authors', 'motsCles'])
            ->orderByDesc('views_count')->orderBy('id')
            ->forPage($page, $perPage)->get();

        return new SearchResult($documents, $total, ['type' => $facetType, 'access_mode' => $facetAccess]);
    }

    private static function fold(string $value): string
    {
        return strtr(mb_strtolower($value), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
    }
}
