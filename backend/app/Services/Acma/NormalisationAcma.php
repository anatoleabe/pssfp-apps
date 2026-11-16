<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Illuminate\Support\Str;

/**
 * Normalisation des valeurs d'ACMA (spec du lot 15, §6). Classe pure.
 *
 * Un matricule ne change pas (règle R1 de « PSSFP Système ») : on retire les espaces et on passe en majuscules, rien
 * d'autre. Les zéros de tête ne sont jamais ajoutés ni retirés, un caractère parasite n'est pas corrigé.
 */
final class NormalisationAcma
{
    /** Matricule normalisé, ou null s'il est vide ou sans lettre ni chiffre. */
    public static function matricule(?string $brut): ?string
    {
        $m = mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $brut));

        return preg_match('/[A-Z0-9]/', $m) === 1 ? $m : null;
    }

    /** Fiche neutralisée dans ACMA : matricule préfixé `zz`, quelle que soit la casse. */
    public static function estNeutralise(?string $brut): bool
    {
        return preg_match('/^\s*zz/i', (string) $brut) === 1;
    }

    /** Caractère autre que lettre ou chiffre dans un matricule normalisé (tiret final, par exemple). */
    public static function aCaractereParasite(string $normalise): bool
    {
        return preg_match('/[^A-Z0-9]/', $normalise) === 1;
    }

    /**
     * Forme sans les zéros de tête de la partie numérique finale (`99XX012` donne `99XX12`) : sert seulement à lister
     * les collisions, jamais à fusionner.
     */
    public static function sansZerosDeTete(string $normalise): string
    {
        return (string) preg_replace('/^(.*\D)0+(\d)/', '$1$2', $normalise);
    }

    /** Texte nettoyé des espaces (bords et répétitions) ; null s'il est vide. */
    public static function texte(?string $brut): ?string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) $brut));

        return $t === '' ? null : $t;
    }

    /** Nom complet d'ACMA (« NOM PRÉNOMS », un seul champ) : espaces réduites, majuscules. */
    public static function nomComplet(?string $brut): ?string
    {
        $t = self::texte($brut);

        return $t === null ? null : mb_strtoupper($t);
    }

    /** `M` (M ou H), `F`, sinon null. */
    public static function sexe(?string $brut): ?string
    {
        return match (mb_strtoupper(trim((string) $brut))) {
            'M', 'H' => 'M',
            'F' => 'F',
            default => null,
        };
    }

    /**
     * Date de naissance (§6.2) : date valide d'une année entre 1940 et 2010, sinon vide et « à vérifier » ; « né vers »
     * (type 3 d'ACMA) : l'année seule, précision `vers`.
     *
     * @return array{date: string|null, annee: int|null, precision: string|null, a_verifier: bool, source: string|null}
     */
    public static function dateNaissance(?string $date, ?string $type, ?string $texte): array
    {
        if (trim((string) $type) === '3') {
            $annee = preg_match('/\b(19[0-9]{2}|20[0-9]{2})\b/', (string) $texte, $m) === 1 ? (int) $m[1] : (int) substr((string) DatesAcma::date($date), 0, 4);
            $valide = $annee >= 1940 && $annee <= 2010;

            return ['date' => null, 'annee' => $valide ? $annee : null, 'precision' => $valide ? 'vers' : null, 'a_verifier' => ! $valide, 'source' => self::texte($texte) ?? self::texte($date)];
        }
        $d = DatesAcma::date($date);
        $valide = $d !== null && substr($d, 0, 4) >= '1940' && substr($d, 0, 4) <= '2010';

        return ['date' => $valide ? $d : null, 'annee' => $valide ? (int) substr($d, 0, 4) : null, 'precision' => null, 'a_verifier' => ! $valide, 'source' => self::texte($date)];
    }

    /** Jetons d'un nom pour la comparaison (§6.3) : sans accents, majuscules, deux lettres au moins. @return list<string> */
    public static function jetons(?string ...$noms): array
    {
        $texte = mb_strtoupper(Str::ascii(implode(' ', array_map(fn (?string $n): string => (string) $n, $noms))));
        $jetons = preg_split('/[^A-Z]+/', $texte, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($jetons, fn (string $j): bool => strlen($j) >= 2)));
    }

    /**
     * Intitulé bilingue « français (anglais) », séparé à la parenthèse finale.
     *
     * @return array{fr: string|null, en: string|null}
     */
    public static function intitule(?string $brut): array
    {
        $t = self::texte($brut);
        if ($t !== null && preg_match('/^(.*\S)\s*\(([^()]+)\)$/u', $t, $m) === 1) {
            return ['fr' => trim($m[1]), 'en' => trim($m[2])];
        }

        return ['fr' => $t, 'en' => null];
    }

    /**
     * Semestre recodé (§5.3) : M1, semestres 1 et 2 ; M2, semestres 3 et 4 (1 ou 2 relus 3 ou 4) ; sinon null.
     */
    public static function semestre(string $niveau, ?string $brut): ?int
    {
        $s = is_numeric(trim((string) $brut)) ? (int) $brut : null;
        if ($niveau === 'M1') {
            return in_array($s, [1, 2], true) ? $s : null;
        }

        return match ($s) {
            1, 3 => 3,
            2, 4 => 4,
            default => null,
        };
    }

    /** Année académique au format `AAAA-AAAA`, la seconde suivant la première ; sinon null. */
    public static function annee(?string $brut): ?string
    {
        $a = trim((string) $brut);
        if (preg_match('/^(\d{4})-(\d{4})$/', $a, $m) !== 1 || (int) $m[2] !== (int) $m[1] + 1) {
            return null;
        }

        return $a;
    }
}
