<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\Versement;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RelancesConfirmations;
use App\Services\Supervision\Controle;

/**
 * Versements non confirmés par la scolarité 7 jours après leur enregistrement (décision du 15/11/2026,
 * VER_NON_CONFIRMES). L'agent de scolarité les confirme sur le reçu bancaire, ou fait annuler un
 * versement que l'auditeur ne justifie pas ; l'escalade va au responsable.
 */
final class VerNonConfirmes implements Controle
{
    public function code(): string
    {
        return 'VER_NON_CONFIRMES';
    }

    public function libelle(): string
    {
        return 'Versements non confirmés par la scolarité après 7 jours';
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
        return RelancesConfirmations::DELAI_JOURS;
    }

    public function responsable(): string
    {
        return 'scolarite_agent';
    }

    public function cas(): array
    {
        $cas = [];
        $versements = Versement::query()->whereNull('annule_at')->whereDoesntHave('confirmation')
            ->where('created_at', '<', RelancesConfirmations::seuil())
            ->with('auditeur.candidature:id,matricule')
            ->orderBy('id')->get(['id', 'auditeur_id', 'montant', 'reference', 'date_versement', 'created_at']);
        foreach ($versements as $v) {
            // Le matricule suffit à retrouver la fiche : pas de nom dans le contrôle interne.
            $matricule = (string) ($v->auditeur?->candidature?->matricule ?? 'sans matricule');
            $cas[(string) $v->id] = [
                'resume' => $matricule.' — '.RecusVersements::fcfa($v->montant).' versés le '.$v->date_versement->format('d/m/Y')
                    .' (bordereau '.$v->reference.'), enregistré le '.$v->created_at->setTimezone('Africa/Douala')->format('d/m/Y').', non confirmé',
                'versement' => $v->id,
            ];
        }

        return $cas;
    }
}
