<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\VirementsAnnonces;
use App\Services\Supervision\Controle;

/** Virements annoncés en attente de crédit depuis plus de 15 jours (spec virements-bordereaux §2.8, VIR_ATTENTE). */
final class VirAttente implements Controle
{
    public function code(): string
    {
        return 'VIR_ATTENTE';
    }

    public function libelle(): string
    {
        return 'Virements annoncés en attente depuis plus de '.VirementsAnnonces::ATTENTE_MAX_JOURS.' jours';
    }

    public function domaine(): string
    {
        return 'Finances';
    }

    public function criticite(): string
    {
        return self::MINEUR;
    }

    public function delaiJours(): int
    {
        return 7;
    }

    public function responsable(): string
    {
        return 'service_financier';
    }

    public function cas(): array
    {
        $cas = [];
        foreach (VirementsAnnonces::enAttente()->where('created_at', '<', now()->subDays(VirementsAnnonces::ATTENTE_MAX_JOURS))->orderBy('id')->get() as $a) {
            $cas[$a->uuid] = [
                'resume' => 'Virement '.$a->reference.' de '.LibelleSituation::fcfa($a->montant).', ordre du '.$a->date_ordre->format('d/m/Y').' : à valider ou à abandonner',
                'virement' => $a->id,
            ];
        }

        return $cas;
    }
}
