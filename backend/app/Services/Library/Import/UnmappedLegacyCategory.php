<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use RuntimeException;

/** Catégorie Joomla qu'aucune règle typée de config/biblio_migration.php ne couvre. */
final class UnmappedLegacyCategory extends RuntimeException
{
    public static function for(string $source, string $path): self
    {
        return new self("Catégorie {$source} sans correspondance : « {$path} ».");
    }
}
