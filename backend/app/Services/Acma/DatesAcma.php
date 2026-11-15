<?php

declare(strict_types=1);

namespace App\Services\Acma;

/**
 * Dates d'ACMA, rendues en texte par MySQL (`0000-00-00` et `2021-06-00` compris). Classe pure : une date
 * impossible donne null, jamais une date « corrigée ».
 */
final class DatesAcma
{
    /** Date `Y-m-d` d'un texte `AAAA-MM-JJ[ hh:mm:ss]` si elle existe au calendrier ; sinon null. */
    public static function date(?string $brut): ?string
    {
        if (preg_match('/^\s*(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?\s*$/', (string) $brut, $m) !== 1) {
            return null;
        }
        [$a, $mo, $j] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return $a >= 1 && checkdate($mo, $j, $a) ? sprintf('%04d-%02d-%02d', $a, $mo, $j) : null;
    }

    /** Horodatage `Y-m-d H:i:s` d'un texte MySQL ; null si la date est impossible. */
    public static function horodatage(?string $brut): ?string
    {
        $date = self::date($brut);
        if ($date === null) {
            return null;
        }
        preg_match('/(\d{2}):(\d{2})(?::(\d{2}))?/', substr(trim((string) $brut), 10), $h);
        $heure = isset($h[1]) ? sprintf('%02d:%02d:%02d', (int) $h[1], (int) $h[2], (int) ($h[3] ?? 0)) : '00:00:00';

        return (int) substr($heure, 0, 2) < 24 && (int) substr($heure, 3, 2) < 60 && (int) substr($heure, 6, 2) < 60 ? $date.' '.$heure : null;
    }

    /** Date hors de l'année académique à un an près : année civile avant la 1re année − 1 ou après la 2e + 1. */
    public static function horsAnnee(string $date, string $annee): bool
    {
        $civile = (int) substr($date, 0, 4);

        return $civile < (int) substr($annee, 0, 4) - 1 || $civile > (int) substr($annee, 5, 4) + 1;
    }
}
