<?php

declare(strict_types=1);

use App\Services\Scolarite\RepriseConfirmations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Reprise des versements existants comme confirmés (décision du 15/11/2026, spec
 * module-4-scolarite-encaissement-confirmation §2.2 point 9 et §5) : confirmés par leur auteur
 * (`saisi_par`), à leur date d'enregistrement, avec leur scan s'il existe. Les versements annulés ne
 * sont pas repris : ils ne comptent plus.
 *
 * Idempotente. Vérifiée dans la même transaction : après la reprise, l'imputé confirmé de chaque
 * inscription égale son imputé, donc aucun état (accès, soldé, à jour, en retard) ne change. Au moindre
 * écart, la migration s'annule en entier et nomme les inscriptions par leur uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        $reprise = app(RepriseConfirmations::class);
        $lignes = $reprise->reprendre();

        $ecarts = $reprise->ecarts();
        if ($ecarts !== []) {
            throw new RuntimeException('Reprise des confirmations annulée, '.count($ecarts).' inscription(s) changeraient d\'état : '
                .implode(' ; ', array_slice($ecarts, 0, 50)).(count($ecarts) > 50 ? ' ; …' : ''));
        }

        Log::info('Reprise des confirmations de versements', ['confirmations' => $lignes]);
    }

    public function down(): void
    {
        // Les confirmations sont immuables : seul le retour arrière de la migration retire celles de la reprise.
        DB::unprepared(<<<'SQL'
            ALTER TABLE confirmations_versements DISABLE TRIGGER confirmations_versements_immuables;
            DELETE FROM confirmations_versements WHERE reprise;
            ALTER TABLE confirmations_versements ENABLE TRIGGER confirmations_versements_immuables;
            SQL);
    }
};
