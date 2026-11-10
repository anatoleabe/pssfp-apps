<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\EtatPaie;
use App\Services\Supervision\Controle;

/** États de paie ni payés ni rejetés, sans changement depuis 15 jours (D38, PAIE_ETATS_BLOQUES). */
final class PaieEtatsBloques implements Controle
{
    public const JOURS = 15;

    public function code(): string
    {
        return 'PAIE_ETATS_BLOQUES';
    }

    public function libelle(): string
    {
        return 'États de paie bloqués depuis plus de '.self::JOURS.' jours';
    }

    public function domaine(): string
    {
        return 'Paie';
    }

    public function criticite(): string
    {
        return self::MAJEUR;
    }

    public function delaiJours(): int
    {
        return 3;
    }

    public function cas(): array
    {
        $cas = [];
        $etats = EtatPaie::query()->whereNotIn('statut', [EtatPaie::PAYE, EtatPaie::REJETE])
            ->where('updated_at', '<', now()->subDays(self::JOURS))->orderBy('numero')->get(['numero', 'statut', 'updated_at']);
        foreach ($etats as $e) {
            $cas[(string) $e->numero] = [
                'resume' => $e->numero.' : « '.(EtatPaie::STATUTS[$e->statut] ?? $e->statut).' » depuis le '.$e->updated_at?->setTimezone('Africa/Douala')->format('d/m/Y'),
                'statut' => $e->statut,
            ];
        }

        return $cas;
    }
}
