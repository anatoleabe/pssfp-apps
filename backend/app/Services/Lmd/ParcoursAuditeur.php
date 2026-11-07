<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\InscriptionAnnuelle;
use App\Models\Parcours;
use Illuminate\Support\Str;

/**
 * Parcours d'une inscription : celui déjà posé ; sinon le tronc commun en M1 et,
 * en M2, l'option dont la spécialité correspond à celle du dossier.
 */
final class ParcoursAuditeur
{
    public function pour(InscriptionAnnuelle $inscription): ?Parcours
    {
        if ($inscription->parcours_id !== null) {
            return $inscription->parcours;
        }
        $candidats = Parcours::query()->where('actif', true)->where('niveau', $inscription->niveau)->get();
        if ($inscription->niveau === 'M1') {
            return $candidats->firstWhere('code', Parcours::TRONC_COMMUN);
        }
        $specialite = self::normaliser((string) $inscription->auditeur?->candidature?->specialite);
        if ($specialite === '') {
            return null;
        }

        return $candidats->first(fn (Parcours $p): bool => $p->specialite !== null && self::normaliser($p->specialite) === $specialite);
    }

    public static function normaliser(string $texte): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texte))));
    }
}
