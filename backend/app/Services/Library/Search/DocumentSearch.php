<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

interface DocumentSearch
{
    /** @param array<string, mixed> $filters Filtres validés de l'API (cf. DocumentController::applyFilters). */
    public function search(string $q, array $filters, int $page, int $perPage): SearchResult;
}
