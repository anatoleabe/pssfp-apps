<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\User;

/**
 * Espace « Finances » du service financier (spec module-4-scolarite-espace-financier §4 et §9, décisions du
 * 16/11/2026) : consultation par `finances.consulter` (service financier, responsable de scolarité,
 * administrateurs) ; les exports nominatifs exigent en plus `scolarite.export`. Chaque export est journalisé
 * avec ses critères, jamais avec une personne.
 */
final class EspaceFinances
{
    public const PERMISSION = 'finances.consulter';

    public const PERMISSION_EXPORT = RegistreVersements::PERMISSION_EXPORT;

    public static function peutConsulter(?User $user): bool
    {
        return (bool) $user?->can(self::PERMISSION);
    }

    public static function peutExporter(?User $user): bool
    {
        return self::peutConsulter($user) && $user->can(self::PERMISSION_EXPORT);
    }

    /**
     * Trace d'un export : l'écran, les critères (filtres et recherche, sans valeur nominative) et le nombre de lignes.
     *
     * @param  array<string, mixed>  $criteres
     */
    public static function journaliserExport(User $auteur, string $ecran, array $criteres, int $lignes): void
    {
        activity('scolarite')->causedBy($auteur)->event('finances_export')
            ->withProperties(['ecran' => $ecran, 'criteres' => $criteres, 'lignes' => $lignes])
            ->log('Export de l\'espace Finances');
    }
}
