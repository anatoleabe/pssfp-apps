<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Texte saisi par un candidat, rendu sûr pour un document Word ou Excel :
 * espaces normalisés, caractères de contrôle et de format retirés (interdits
 * en XML, ils rendraient le fichier illisible).
 */
final class TexteImprimable
{
    public static function nettoyer(?string $texte): string
    {
        $texte = (string) preg_replace('/\s+/u', ' ', (string) $texte);
        $texte = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $texte);

        return trim($texte);
    }

    public static function majuscules(?string $texte): string
    {
        return mb_strtoupper(self::nettoyer($texte));
    }
}
