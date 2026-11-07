<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Candidature;
use App\Models\User;

/**
 * Dossier de scolarité du compte connecté : dernier dossier retenu, non retiré et
 * doté d'une fiche auditeur. Un brouillon dans une campagne suivante ne masque
 * pas la scolarité en cours. Jamais un identifiant reçu de l'extérieur.
 */
final class DossierAuditeurConnecte
{
    /** @param  list<string>  $avec  relations à charger en plus */
    public function pour(User $user, array $avec = []): ?Candidature
    {
        return Candidature::query()
            ->where('user_id', $user->id)
            ->where('statut', Candidature::STATUT_ACCEPTE)
            ->whereNull('withdrawn_at')
            ->whereHas('auditeur')
            ->with(['campagne', 'auditeur.inscriptionCourante.bareme', ...$avec])
            ->orderByDesc('matricule_attribue_at')
            ->orderByDesc('id')
            ->first();
    }
}
