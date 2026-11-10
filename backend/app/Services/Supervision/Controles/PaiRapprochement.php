<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\RapprochementVirements;
use App\Services\Supervision\Controle;

/** Écarts entre la paie des enseignants et les virements reçus (lot 13c-1). */
final class PaiRapprochement implements Controle
{
    public function __construct(private readonly RapprochementVirements $rapprochement) {}

    public function code(): string
    {
        return 'PAI_RAPPROCHEMENT';
    }

    public function libelle(): string
    {
        return 'Écarts entre la paie et les virements';
    }

    public function domaine(): string
    {
        return 'Paie';
    }

    public function criticite(): string
    {
        return self::BLOQUANT;
    }

    public function delaiJours(): int
    {
        return 5;
    }

    public function responsable(): string
    {
        return 'service_financier';
    }

    public function cas(): array
    {
        $cas = [];
        foreach ($this->rapprochement->anomalies() as $a) {
            $cle = ($a['etat'] ?? 'sans-etat').'|'.$a['enseignant_id'].($a['etat'] === null ? '|'.$a['references'] : '');
            $cas[$cle] = [
                'resume' => ($a['etat'] ?? 'Sans état').' — '.$a['enseignant'].' : '.(RapprochementVirements::SITUATIONS[$a['situation']] ?? $a['situation'])
                    .' (écart '.EtatsPaie::fcfa((int) $a['ecart']).')',
                'etat' => $a['etat'],
                'situation' => $a['situation'],
            ];
        }

        return $cas;
    }
}
