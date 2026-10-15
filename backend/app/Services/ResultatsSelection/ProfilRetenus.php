<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

/**
 * Statistiques complémentaires du PV sur les candidats retenus : situation
 * professionnelle, âge, diplôme d'accès et indicateurs de synthèse.
 */
final readonly class ProfilRetenus
{
    public function __construct(
        /** Statut actuel (lignes) × spécialité (colonnes, en sigles). */
        public Repartition $parStatut,
        /** Tranche d'âge (lignes) × genre (colonnes). */
        public Repartition $parAge,
        /** Spécialité × diplôme d'accès (Licence, Master). */
        public Repartition $parDiplome,
        /** Spécialité × domaine du diplôme d'accès. */
        public Repartition $parDomaine,
        public ?float $ageMoyen,
        public ?int $ageMinimum,
        public ?int $ageMaximum,
        public int $total,
        public int $femmes,
        public int $distanciel,
        public int $fonctionnaires,
        public int $secteurPublic,
        public int $anglophones,
    ) {}

    /** « 95 (41 %) » ; pourcentage arrondi à l'unité. */
    public function part(int $effectif): string
    {
        $pourcentage = $this->total === 0 ? 0 : (int) round($effectif * 100 / $this->total);

        return $effectif.' ('.$pourcentage.' %)';
    }
}
