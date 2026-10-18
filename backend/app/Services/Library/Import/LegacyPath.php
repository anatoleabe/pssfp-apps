<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/** Ancienne URL Joomla (chemin tel que le navigateur l'envoie) et sa forme, pour le rapport. */
final readonly class LegacyPath
{
    public function __construct(
        public string $path,
        public string $form,
    ) {}
}
