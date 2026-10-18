<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use InvalidArgumentException;

/** Options de biblio:import : simulation, limite du nombre d'éléments, source unique. */
final readonly class ImportOptions
{
    public const SOURCES = [LegacyMapping::SOURCE_DOCMAN, LegacyMapping::SOURCE_ALEXANDRIA];

    public function __construct(
        public bool $dryRun = false,
        public ?int $limit = null,
        public ?string $only = null,
    ) {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('--limit attend un entier strictement positif.');
        }
        if ($only !== null && ! in_array($only, self::SOURCES, true)) {
            throw new InvalidArgumentException('--only attend « docman » ou « alexandria ».');
        }
    }

    public function includes(string $source): bool
    {
        return $this->only === null || $this->only === $source;
    }
}
