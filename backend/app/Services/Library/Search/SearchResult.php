<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

use App\Models\Document;
use Illuminate\Support\Collection;

final readonly class SearchResult
{
    /**
     * @param  Collection<int, Document>  $documents
     * @param  array<string, array<string, int>>  $facets
     * @param  array<string, string>  $highlights  uuid => titre avec <mark>
     */
    public function __construct(
        public Collection $documents,
        public int $total,
        public array $facets = [],
        public array $highlights = [],
    ) {}
}
