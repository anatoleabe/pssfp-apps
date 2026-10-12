<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Nombres en toutes lettres, orthographe rectifiée de 1990 (traits d'union
 * partout), pour les actes administratifs : « L'an deux-mille-vingt-six »,
 * « QUATORZIEME PROMOTION ». Plage 0–9 999, largement suffisante ici.
 */
final class NombreEnLettres
{
    private const UNITES = [
        'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
        'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
    ];

    private const DIZAINES = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    public static function cardinal(int $n): string
    {
        if ($n < 0 || $n > 9999) {
            throw new InvalidArgumentException("Nombre hors plage (0–9999) : {$n}");
        }

        if ($n === 0) {
            return self::UNITES[0];
        }

        $mots = [];
        $milliers = intdiv($n, 1000);
        $centaines = intdiv($n % 1000, 100);
        $reste = $n % 100;

        if ($milliers > 0) {
            // « mille » est invariable et ne prend jamais « un » devant.
            $mots[] = $milliers === 1 ? 'mille' : self::moinsDeCent($milliers).'-mille';
        }

        if ($centaines > 0) {
            $cent = $centaines === 1 ? 'cent' : self::UNITES[$centaines].'-cent';
            // « cents » ne prend un s que s'il termine le nombre et est multiplié.
            $mots[] = ($centaines > 1 && $reste === 0) ? $cent.'s' : $cent;
        }

        if ($reste > 0) {
            $mots[] = self::moinsDeCent($reste);
        }

        return implode('-', $mots);
    }

    public static function ordinal(int $n): string
    {
        if ($n === 1) {
            return 'premier';
        }

        $cardinal = self::cardinal($n);

        return match (true) {
            str_ends_with($cardinal, 'cinq') => $cardinal.'uième',
            str_ends_with($cardinal, 'neuf') => substr($cardinal, 0, -1).'vième',
            str_ends_with($cardinal, 'vingts'), str_ends_with($cardinal, 'cents') => substr($cardinal, 0, -1).'ième',
            str_ends_with($cardinal, 'e') => substr($cardinal, 0, -1).'ième',
            default => $cardinal.'ième',
        };
    }

    private static function moinsDeCent(int $n): string
    {
        if ($n <= 16) {
            return self::UNITES[$n];
        }

        if ($n < 20) {
            return 'dix-'.self::UNITES[$n - 10];
        }

        $dizaine = intdiv($n, 10);
        $unite = $n % 10;

        // 70–79 et 90–99 se construisent sur 60 + (10…19) et 80 + (10…19).
        if ($dizaine === 7 || $dizaine === 9) {
            $base = $dizaine === 7 ? 'soixante' : 'quatre-vingt';
            $liaison = ($dizaine === 7 && $unite === 1) ? '-et-' : '-';

            return $base.$liaison.self::moinsDeCent(10 + $unite);
        }

        if ($dizaine === 8) {
            return $unite === 0 ? 'quatre-vingts' : 'quatre-vingt-'.self::UNITES[$unite];
        }

        $base = self::DIZAINES[$dizaine];

        return match ($unite) {
            0 => $base,
            1 => $base.'-et-un',
            default => $base.'-'.self::UNITES[$unite],
        };
    }
}
