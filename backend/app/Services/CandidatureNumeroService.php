<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampagneCandidature;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Génère le `numero_dossier` d'une candidature au format P{N}{YY}-{ID} (ex P14026-001).
 *
 * Garanties :
 * - Atomicité concurrente via `pg_advisory_xact_lock(42, campagne_id)` —
 *   namespace 42 = numéros candidatures, clé = id campagne.
 * - Robuste au hard-delete : utilise `MAX(numero_id_suffix)` plutôt qu'un `count()+1`.
 *   Si un dossier est purgé (RGPD, erreur admin), le compteur ne recule pas
 *   et ne collisionne pas avec un numéro déjà attribué.
 *
 * Règle stricte : `Candidature::forceDelete()` est interdit en production
 * (sauf via une commande artisan dédiée avec confirmation explicite, à coder ultérieurement).
 *
 * Cf. spec module 5 §M5 + ADR à venir sur la politique de purge candidatures.
 */
final class CandidatureNumeroService
{
    private const ADVISORY_LOCK_NAMESPACE = 42;

    public function __construct(private readonly ConnectionInterface $db) {}

    public function generate(CampagneCandidature $campagne): string
    {
        if ($campagne->id === null) {
            throw new RuntimeException(
                'CampagneCandidature must be persisted before numero generation.'
            );
        }

        return $this->db->transaction(function () use ($campagne): string {
            $this->db->statement(
                'SELECT pg_advisory_xact_lock(?, ?)',
                [self::ADVISORY_LOCK_NAMESPACE, $campagne->id]
            );

            $lastSuffix = $this->lastNumericSuffix($campagne);
            $next = $lastSuffix + 1;

            return $campagne->prefix_numero.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Récupère le suffixe numérique max parmi tous les dossiers (y compris soft-deleted)
     * de la campagne. Robuste aux trous causés par d'éventuels hard deletes.
     */
    private function lastNumericSuffix(CampagneCandidature $campagne): int
    {
        $row = $this->db->table('candidatures')
            ->where('campagne_id', $campagne->id)
            ->selectRaw(
                "MAX(CAST(SUBSTRING(numero_dossier FROM '[0-9]+$') AS INTEGER)) AS last_id"
            )
            ->first();

        return (int) ($row->last_id ?? 0);
    }
}
