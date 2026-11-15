<?php

declare(strict_types=1);

namespace App\Services\Acma;

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
