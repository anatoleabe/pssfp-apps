<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/** Mot-clé du thésaurus à créer par l'import (firstOrCreate par slug). */
final readonly class KeywordRef
{
    public function __construct(
        public string $slug,
        public string $label,
        public ?string $parentSlug,
    ) {}
}
