<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use App\Models\Document;

/**
 * Slugs uniques parmi les documents (supprimés compris) et ceux déjà attribués pendant l'import :
 * suffixe -2, -3… dans l'ordre de traitement (identifiant Joomla croissant), longueur ≤ 160.
 */
final class SlugAllocator
{
    /** @var array<string, true> */
    private array $reserved = [];

    public function allocate(string $base): string
    {
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? $base : self::suffixed($base, $n);
            if (isset($this->reserved[$candidate]) || Document::withTrashed()->where('slug', $candidate)->exists()) {
                continue;
            }
            $this->reserved[$candidate] = true;

            return $candidate;
        }
    }

    /** Annule une attribution (élément en échec, transaction annulée). */
    public function release(string $slug): void
    {
        unset($this->reserved[$slug]);
    }

    private static function suffixed(string $base, int $n): string
    {
        $suffix = '-'.$n;

        return rtrim(substr($base, 0, LegacyText::SLUG_MAX_LENGTH - strlen($suffix)), '-').$suffix;
    }
}
