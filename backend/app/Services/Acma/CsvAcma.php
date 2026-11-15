<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Generator;

/**
 * CSV du transit ACMA (spec du lot 15, §10.1) : UTF-8, RFC 4180, fin de ligne CRLF. Toute valeur est entre guillemets ;
 * NULL s'écrit `\N` sans guillemets, ce qui le distingue du texte « \N ».
 */
final class CsvAcma
{
    public const NUL = '\N';

    /** @param  list<string|null>  $valeurs */
    public static function ligne(array $valeurs): string
    {
        return implode(',', array_map(
            static fn (?string $v): string => $v === null ? self::NUL : '"'.str_replace('"', '""', $v).'"',
            $valeurs,
        ))."\r\n";
    }

    /**
     * Enregistrements d'un fichier écrit par ligne(), un champ entre guillemets pouvant tenir sur plusieurs lignes.
     *
     * @return Generator<int, list<string|null>>
     */
    public static function lire(string $fichier): Generator
    {
        $flux = fopen($fichier, 'rb');
        if ($flux === false) {
            throw new ErreurAcma('CSV illisible : '.basename($fichier));
        }
        try {
            $tampon = '';
            while (($morceau = fgets($flux)) !== false) {
                $tampon .= $morceau;
                if (substr_count($tampon, '"') % 2 !== 0) {
                    continue;
                }
                yield self::analyser(substr($tampon, -2) === "\r\n" ? substr($tampon, 0, -2) : rtrim($tampon, "\n"));
                $tampon = '';
            }
            if ($tampon !== '') {
                throw new ErreurAcma('CSV tronqué : '.basename($fichier));
            }
        } finally {
            fclose($flux);
        }
    }

    /** @return list<string|null> */
    public static function analyser(string $enregistrement): array
    {
        if (preg_match_all('/(?:^|,)(?:"((?:[^"]++|"")*+)"|([^,"]*+))/', $enregistrement, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            throw new ErreurAcma('Enregistrement CSV illisible.');
        }
        $valeurs = [];
        $longueur = 0;
        foreach ($m as $champ) {
            $longueur += strlen($champ[0]);
            if ($champ[1] !== null) {
                $valeurs[] = str_replace('""', '"', $champ[1]);
            } else {
                $brut = (string) $champ[2];
                $valeurs[] = $brut === self::NUL ? null : $brut;
            }
        }
        if ($longueur !== strlen($enregistrement)) {
            throw new ErreurAcma('Enregistrement CSV mal formé.');
        }

        return $valeurs;
    }
}
