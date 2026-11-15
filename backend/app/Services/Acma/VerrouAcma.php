<?php

declare(strict_types=1);

namespace App\Services\Acma;

use Illuminate\Support\Facades\DB;

/**
 * Exclusion mutuelle des commandes de la reprise (charger, reprendre, geler, purger le transit) : un verrou consultatif
 * PostgreSQL, pris dans la transaction de la commande et libéré avec elle. Une commande lancée pendant une autre attend
 * qu'elle se termine, puis revérifie l'état (gel, transit) sous le verrou.
 */
final class VerrouAcma
{
    public const CLE = 1_500_150_015;

    public static function prendre(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new ErreurAcma('Verrou de la reprise pris hors transaction.');
        }
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::CLE]);
    }
}
