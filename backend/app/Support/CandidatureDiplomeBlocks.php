<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalisation des blocs répétables « autres diplômes » et « formations
 * professionnelles », stockés en JSONB sur `candidatures`.
 *
 * Le client envoie un tableau d'objets. On ne fait jamais confiance à sa forme :
 * seules les clés attendues survivent, les valeurs sont typées, les lignes vides
 * disparaissent et le nombre de lignes est plafonné. Aucun JSON arbitraire
 * n'atteint la base.
 */
final class CandidatureDiplomeBlocks
{
    public const MAX_ROWS = 10;

    /** @var list<string> */
    public const AUTRE_DIPLOME_KEYS = ['intitule', 'etablissement', 'annee'];

    /** @var list<string> */
    public const FORMATION_PRO_KEYS = ['centre', 'qualification', 'annee'];

    /**
     * @param  list<string>  $keys
     * @return list<array<string, string|int|null>>
     */
    public static function normalize(mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach (array_slice(array_values($rows), 0, self::MAX_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = [];
            foreach ($keys as $key) {
                $clean[$key] = $key === 'annee'
                    ? self::normalizeYear($row[$key] ?? null)
                    : self::normalizeText($row[$key] ?? null);
            }

            $hasValue = array_filter($clean, static fn ($value): bool => $value !== null) !== [];
            if ($hasValue) {
                $normalized[] = $clean;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, string|int|null>  $row
     * @param  list<string>  $keys
     */
    public static function isRowComplete(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if ($value === null || $value === '') {
                return false;
            }
        }

        return true;
    }

    private static function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizeYear(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '' && ctype_digit(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }
}
