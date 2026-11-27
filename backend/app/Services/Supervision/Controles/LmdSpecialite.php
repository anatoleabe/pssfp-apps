<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Services\Lmd\ChangementSpecialite;
use App\Services\Supervision\Controle;

/**
 * Auditeurs de M2 dont le parcours de l'année n'est pas la spécialité du dossier (27/11/2026) : la fiche, les listes
 * et les UE ne disent pas la même chose. L'administrateur tranche avec « Changer la spécialité » ; l'anomalie se
 * referme d'elle-même. Résumé : matricule et codes, sans nom.
 */
final class LmdSpecialite implements Controle
{
    public function code(): string
    {
        return 'LMD_SPECIALITE';
    }

    public function libelle(): string
    {
        return 'Parcours de M2 différent de la spécialité du dossier';
    }

    public function domaine(): string
    {
        return 'Scolarité';
    }

    public function criticite(): string
    {
        return self::MAJEUR;
    }

    public function delaiJours(): int
    {
        return 7;
    }

    public function responsable(): string
    {
        return 'admin';
    }

    public function cas(): array
    {
        $cas = [];
        foreach (ChangementSpecialite::ecarts() as $a) {
            $cas[(string) $a->uuid] = [
                'resume' => ($a->candidature?->matricule ?: 'sans matricule').' — parcours '.$a->inscriptionCourante->parcours->code
                    .', dossier « '.($a->candidature?->specialite ?: 'sans spécialité').' »',
                'auditeur' => (string) $a->uuid,
            ];
        }

        return $cas;
    }
}
