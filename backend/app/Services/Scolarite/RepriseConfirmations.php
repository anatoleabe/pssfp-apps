<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use Illuminate\Support\Facades\DB;

/**
 * Reprise des versements d'avant le 15/11/2026 comme confirmés (spec
 * module-4-scolarite-encaissement-confirmation §2.2 point 9) : par leur auteur (`saisi_par`), à leur
 * date d'enregistrement, avec leur scan s'il existe. Les versements annulés ne sont pas repris : ils
 * ne comptent plus. Idempotente. Appelée par la migration 2026_12_04_100200, qui vérifie `ecarts()`.
 */
final class RepriseConfirmations
{
    /** @return int nombre de confirmations posées */
    public function reprendre(): int
    {
        $versements = Versement::query()->whereNull('annule_at')->whereDoesntHave('confirmation')
            ->select(['id', 'saisi_par'])
            // Valeurs fixes, aucune saisie : l'insertion en bloc reste une seule requête.
            ->selectRaw('COALESCE(created_at, now())')
            ->addSelect('justificatif_path')
            ->selectRaw('false, true, now()')
            ->orderBy('id');

        return DB::table('confirmations_versements')->insertUsing(
            ['versement_id', 'confirme_par', 'confirme_at', 'justificatif_path', 'attestation', 'reprise', 'created_at'],
            $versements->toBase(),
        );
    }

    /**
     * Inscriptions dont l'imputé confirmé diffère de l'imputé : leur état changerait avec la règle « à jour ».
     * Vide après la reprise, puisque tout l'argent valide y est confirmé.
     *
     * @return list<string> uuid des inscriptions
     */
    public function ecarts(): array
    {
        return InscriptionAnnuelle::query()
            ->whereRaw(InscriptionAnnuelle::VERSE_COMPTE.' <> '.InscriptionAnnuelle::VERSE_CONFIRME)
            ->orderBy('id')->pluck('uuid')->all();
    }
}
