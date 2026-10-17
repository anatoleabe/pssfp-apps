<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

use Illuminate\Support\Facades\Log;
use Throwable;

/** Essaie la recherche principale, bascule sur la base en cas de panne (ADR-0003). */
final readonly class FallbackDocumentSearch implements DocumentSearch
{
    public function __construct(private DocumentSearch $primary, private DocumentSearch $fallback) {}

    public function search(string $q, array $filters, int $page, int $perPage): SearchResult
    {
        try {
            return $this->primary->search($q, $filters, $page, $perPage);
        } catch (Throwable $e) {
            Log::warning('Recherche bibliothèque : repli sur PostgreSQL', ['error' => $e->getMessage()]);

            return $this->fallback->search($q, $filters, $page, $perPage);
        }
    }
}
