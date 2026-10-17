<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

use App\Models\Document;
use Meilisearch\Endpoints\Indexes;

/** Recherche principale : facettes, tolérance aux fautes, extraits surlignés. */
final class MeilisearchDocumentSearch implements DocumentSearch
{
    /** Balises sentinelles : Meilisearch n'échappe pas le texte source. */
    private const MARK_OPEN = '__pssfp_mark__';

    private const MARK_CLOSE = '__/pssfp_mark__';

    public function search(string $q, array $filters, int $page, int $perPage): SearchResult
    {
        $raw = Document::search($q, function (Indexes $index, string $query, array $options) use ($filters, $page, $perPage) {
            return $index->rawSearch($query, array_merge($options, [
                'filter' => self::filterExpression($filters),
                'facets' => ['type', 'access_mode'],
                'attributesToHighlight' => ['title'],
                'highlightPreTag' => self::MARK_OPEN,
                'highlightPostTag' => self::MARK_CLOSE,
                'hitsPerPage' => $perPage,
                'page' => $page,
            ]));
        })->raw();

        $ids = array_column($raw['hits'], 'id');
        $documents = Document::query()->published()->with(['authors', 'motsCles'])->whereIn('id', $ids)->get()
            ->sortBy(fn (Document $d): int|false => array_search($d->id, $ids, true))->values();

        // Un document dépublié encore indexé ne doit pas fuiter par son titre surligné.
        $returned = $documents->pluck('uuid')->flip();
        $highlights = [];
        foreach ($raw['hits'] as $hit) {
            if ($returned->has($hit['uuid'])) {
                $highlights[$hit['uuid']] = self::highlight($hit['_formatted']['title'] ?? $hit['title']);
            }
        }

        return new SearchResult($documents, (int) ($raw['totalHits'] ?? 0), $raw['facetDistribution'] ?? [], $highlights);
    }

    /** Échappe le titre puis restaure les seules balises <mark> posées par nos sentinelles. */
    public static function highlight(string $formatted): string
    {
        return strtr(e($formatted), [
            e(self::MARK_OPEN) => '<mark>',
            e(self::MARK_CLOSE) => '</mark>',
        ]);
    }

    /**
     * Filtres validés de l'API → expressions Meilisearch, dans un ordre fixe.
     * Les chaînes sont échappées (guillemets, antislash) : jamais d'injection
     * de syntaxe de filtre depuis la requête.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public static function filterExpression(array $filters): array
    {
        $quoted = fn (string $attribute, mixed $value): string => sprintf('%s = "%s"', $attribute, addcslashes((string) $value, '"\\'));
        $present = fn (string $key): bool => isset($filters[$key]) && $filters[$key] !== '';

        return array_values(array_filter([
            $present('type') ? $quoted('type', $filters['type']) : null,
            $present('acces') ? $quoted('access_mode', $filters['acces']) : null,
            $present('annee_min') ? sprintf('year >= %d', (int) $filters['annee_min']) : null,
            $present('annee_max') ? sprintf('year <= %d', (int) $filters['annee_max']) : null,
            $present('mot_cle') ? $quoted('mots_cles', $filters['mot_cle']) : null,
            $present('specialite') ? $quoted('specialite', $filters['specialite']) : null,
            $present('promotion') ? sprintf('promotion = %d', (int) $filters['promotion']) : null,
            $present('langue') ? $quoted('language', $filters['langue']) : null,
        ]));
    }
}
