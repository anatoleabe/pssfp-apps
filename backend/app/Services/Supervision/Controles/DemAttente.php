<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\DemandeAutorisation;
use App\Services\Messagerie\TypesDemande;
use App\Services\Supervision\Controle;
use App\Services\Supervision\Supervision;

/** Demandes d'autorisation sans réponse après leur échéance (D31, DEM_ATTENTE). */
final class DemAttente implements Controle
{
    public function code(): string
    {
        return 'DEM_ATTENTE';
    }

    public function libelle(): string
    {
        return 'Demandes d\'autorisation sans réponse dans le délai';
    }

    public function domaine(): string
    {
        return 'Messagerie';
    }

    public function criticite(): string
    {
        return self::MAJEUR;
    }

    public function delaiJours(): int
    {
        return 1;
    }

    public function responsable(): string
    {
        return 'scolarite_responsable';
    }

    public function cas(): array
    {
        $cas = [];
        $demandes = DemandeAutorisation::query()->whereIn('statut', DemandeAutorisation::EN_ATTENTE)
            ->where('echeance', '<', now())->orderBy('id')->get(['id', 'numero', 'type', 'statut', 'echeance']);
        foreach ($demandes as $d) {
            $cas[$d->numero] = [
                'resume' => $d->numero.' — '.(TypesDemande::TYPES[$d->type]['libelle'] ?? $d->type).' : '
                    .mb_strtolower(DemandeAutorisation::STATUTS[$d->statut]).', échéance dépassée depuis le '
                    .$d->echeance->setTimezone(Supervision::FUSEAU)->format('d/m/Y à H:i'),
                'demande' => $d->id,
            ];
        }

        return $cas;
    }
}
