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
    /** Code d'erreur Meilisearch (`ApiException::$errorCode`) d'un index inexistant (HTTP 404). */
    private const INDEX_NOT_FOUND = 'index_not_found';

    public function __construct(private DocumentSearch $primary, private DocumentSearch $fallback) {}

    public function search(string $q, array $filters, int $page, int $perPage): SearchResult
    {
        try {
            return $this->primary->search($q, $filters, $page, $perPage);
        } catch (CommunicationException|ApiException|ClientExceptionInterface $e) {
            // Index absent (jamais créé, supprimé) : panne d'exploitation, pas un bug de requête.
            // On sert la base et on alerte (niveau error : il faut relancer `scout:import`).
            if ($e instanceof ApiException && $e->errorCode === self::INDEX_NOT_FOUND) {
                Log::error('Recherche bibliothèque : index Meilisearch absent, repli sur PostgreSQL', ['code' => $e->errorCode, 'error' => $e->getMessage()]);

                return $this->fallback->search($q, $filters, $page, $perPage);
            }

            // Toute autre 4xx (filtre invalide, etc.) est un bug de notre côté : on la laisse remonter.
            if ($e instanceof ApiException && $e->httpStatus < 500) {
                throw $e;
            }

            Log::warning('Recherche bibliothèque : repli sur PostgreSQL', ['exception' => $e::class, 'error' => $e->getMessage()]);

            return $this->fallback->search($q, $filters, $page, $perPage);
        }
    }
}
