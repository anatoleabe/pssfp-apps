<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/**
 * Décision pour un fichier trouvé sur l'ancien serveur : une catégorie, une exclusion motivée,
 * ou ni l'une ni l'autre (aucune règle). `folderRule` = clé de la règle retenue, '' si aucune.
 */
final readonly class ServerFileRule
{
    public function __construct(
        public ?MappedCategory $category,
        public ?string $exclusionReason,
        public string $folderRule,
    ) {}
}
