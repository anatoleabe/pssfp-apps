<?php

declare(strict_types=1);

namespace App\Services\ExportsRetenus;

use App\Models\CampagneCandidature;
use App\Models\User;
use RuntimeException;

/**
 * Point d'entrée des téléchargements : vérifie que les matricules sont
 * attribués, produit le fichier et journalise qui a extrait quoi — ces
 * fichiers sont nominatifs, et celui de Moodle contient des mots de passe.
 */
final class ExportsRetenus
{
    public function __construct(private readonly ExportsRetenusExcel $excel) {}

    /** « PROMO14_TC_2026_2027 », comme la cohorte de la 13e promotion. */
    public static function cohorteParDefaut(CampagneCandidature $campagne): string
    {
        return 'PROMO'.(int) $campagne->promotion_numero.'_TC_'.str_replace('-', '_', $campagne->anneeAcademique());
    }

    /** Retenus encore sans matricule : l'export les omettrait, on bloque. */
    public static function sansMatricule(CampagneCandidature $campagne): int
    {
        return AttributionMatricules::retenus($campagne)->whereNull('matricule')->count();
    }

    /**
     * @return array{contenu: string, nom: string, mime: string, lignes: int}
     */
    public function generer(CampagneCandidature $campagne, string $type, User $auteur, string $cohorteMoodle = ''): array
    {
        if (! in_array($type, ExportsRetenusExcel::TYPES, true)) {
            throw new RuntimeException("Export inconnu : {$type}");
        }
        // Le fichier Moodle contient les mots de passe : administration seule,
        // quel que soit l'appelant.
        if ($type === ExportsRetenusExcel::MOODLE) {
            abort_unless($auteur->can(IdentifiantsMoodle::PERMISSION), 403);
        }
        if (self::sansMatricule($campagne) > 0) {
            throw new RuntimeException('Des retenus n\'ont pas encore de matricule : attribuez les matricules avant d\'exporter.');
        }

        // whereNotNull : un dossier accepté entre la vérification et la lecture
        // n'apparaît pas à moitié rempli.
        $retenus = AttributionMatricules::retenus($campagne)->whereNotNull('matricule')->orderBy('rang_retenu')->get();
        $contenu = $this->excel->generer($type, $campagne, $retenus, trim($cohorteMoodle));

        activity('candidatures')->causedBy($auteur)->performedOn($campagne)
            ->event('export_retenus')
            ->withProperties(['type' => $type, 'lignes' => $retenus->count()])
            ->log('Export des retenus téléchargé');

        return [
            'contenu' => $contenu,
            'nom' => ExportsRetenusExcel::nomFeuille($type, $campagne).'.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'lignes' => $retenus->count(),
        ];
    }
}
