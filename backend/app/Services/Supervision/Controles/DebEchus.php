<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\DeblocageAcces;
use App\Services\Supervision\Controle;

/**
 * Accès anticipés échus et non régularisés (Scolarité, lot A1 §5, DEB_ECHUS).
 * L'agent de scolarité relance ou fait révoquer ; l'escalade va au responsable.
 */
final class DebEchus implements Controle
{
    public function code(): string
    {
        return 'DEB_ECHUS';
    }

    public function libelle(): string
    {
        return 'Accès anticipés échus et non régularisés';
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
        return 'scolarite_agent';
    }

    public function cas(): array
    {
        $cas = [];
        $echus = DeblocageAcces::query()->echus()->with('inscription.auditeur.candidature:id,matricule')
            ->orderBy('id')->get(['id', 'inscription_annuelle_id', 'type', 'echeance', 'relances']);
        foreach ($echus as $d) {
            // Le matricule suffit à retrouver la fiche : pas de nom dans le contrôle interne.
            $matricule = (string) ($d->inscription?->auditeur?->candidature?->matricule ?? 'sans matricule');
            $cas[(string) $d->id] = [
                'resume' => $matricule.' — '.$d->libelleType().' : échéance du '.$d->echeance->format('d/m/Y').' dépassée, '
                    .$d->relances.' relance(s) envoyée(s) à l\'auditeur',
                'deblocage' => $d->id,
                'type' => $d->type,
            ];
        }

        return $cas;
    }
}
