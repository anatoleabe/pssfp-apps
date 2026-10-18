<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

use Illuminate\Support\Facades\Log;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use Psr\Http\Client\ClientExceptionInterface;

/** Essaie la recherche principale, bascule sur la base en cas de panne (ADR-0003). */
final readonly class FallbackDocumentSearch implements DocumentSearch
{
    public function __construct(private DocumentSearch $primary, private DocumentSearch $fallback) {}

    public function search(string $q, array $filters, int $page, int $perPage): SearchResult
    {
        try {
            return $this->primary->search($q, $filters, $page, $perPage);
        } catch (CommunicationException|ApiException|ClientExceptionInterface $e) {
            // Une 4xx (filtre invalide, etc.) est un bug de notre côté : on la laisse remonter.
            if ($e instanceof ApiException && $e->httpStatus < 500) {
                throw $e;
            }

            Log::warning('Recherche bibliothèque : repli sur PostgreSQL', ['exception' => $e::class, 'error' => $e->getMessage()]);

            return $this->fallback->search($q, $filters, $page, $perPage);
        }
    }
}
