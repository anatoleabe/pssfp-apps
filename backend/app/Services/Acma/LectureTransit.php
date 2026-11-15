<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/** Lecture du schéma `acma_transit` (tout en texte), par lots sur le rang `_ligne`. */
final class LectureTransit
{
    public static function table(string $table): Builder
    {
        return DB::table(ChargementTransit::SCHEMA.'.'.$table);
    }

    /**
     * @param  list<string>  $colonnes
     * @return LazyCollection<int, object>
     */
    public static function lignes(string $table, array $colonnes, int $lot = 2000): LazyCollection
    {
        return self::table($table)->select(array_merge(['_ligne'], $colonnes))->lazyById($lot, '_ligne');
    }

    /** @return array{manifeste: array<string, mixed>, manifeste_sha256: string, charge_le: string} */
    public static function chargement(): array
    {
        if (! ChargementTransit::charge()) {
            throw new ErreurAcma('Transit ACMA absent : lancer d\'abord acma:charger.');
        }
        $c = self::table('_chargement')->orderByDesc('charge_le')->first();

        return [
            'manifeste' => json_decode((string) $c->manifeste, true, 64, JSON_THROW_ON_ERROR),
            'manifeste_sha256' => (string) $c->manifeste_sha256,
            'charge_le' => (string) $c->charge_le,
        ];
    }

    /** Entier d'une valeur ACMA en texte (montant en flottant compris) ; null si vide ou non numérique. */
    public static function nombre(?string $v): ?float
    {
        $v = trim((string) $v);

        return $v !== '' && is_numeric($v) ? (float) $v : null;
    }
}
