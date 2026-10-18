<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/** Fichier retrouvé sous --files : chemin absolu, chemin relatif réel, correspondance tolérante ou exacte. */
final readonly class LocatedFile
{
    public function __construct(
        public string $path,
        public string $relative,
        public bool $tolerant,
    ) {}
}
