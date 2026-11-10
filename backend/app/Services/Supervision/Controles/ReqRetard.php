<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Services\Lmd\ControleGestion;
use App\Services\Supervision\Controle;

/** Requêtes ouvertes au-delà du délai de traitement réglé (lot 13c-2). */
final class ReqRetard implements Controle
{
    public function __construct(private readonly ControleGestion $gestion) {}

    public function code(): string
    {
        return 'REQ_RETARD';
    }

    public function libelle(): string
    {
        return 'Requêtes en retard de traitement';
    }

    public function domaine(): string
    {
        return 'Requêtes';
    }

    public function criticite(): string
    {
        return self::MAJEUR;
    }

    public function delaiJours(): int
    {
        return 2;
    }

    public function responsable(): string
    {
        return 'scolarite_agent';
    }

    public function cas(): array
    {
        $cas = [];
        foreach ($this->gestion->dossiersEnRetard() as $d) {
            if ($d['dossier'] === 'Requête') {
                $cas[$d['numero']] = ['resume' => $d['numero'].' : « '.$d['statut'].' », '.$d['jours'].' jour(s) de retard', 'statut' => $d['statut']];
            }
        }

        return $cas;
    }
}
