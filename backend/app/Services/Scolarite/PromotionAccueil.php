<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Services\ExportsRetenus\ExportsRetenus;

/**
 * Promotion d'accueil d'un auditeur (spec de la réinscription des anciens, §10, sous-lot 15l) : celle dont il suit
 * les cours dans l'année de son inscription courante (M1 : la promotion qui entre cette année ; M2 : celle entrée
 * l'année d'avant). Elle peut différer de sa promotion de rattachement (celle de son dossier) : ancien réinscrit
 * rattaché à sa promotion d'origine, P13 redoublant en M1.
 *
 * Pour un dossier ordinaire de la promotion en cours, les deux se confondent : rien ne change.
 */
final class PromotionAccueil
{
    /** Promotion d'accueil de l'inscription courante, sinon la promotion du dossier. */
    public static function pour(Candidature $c): ?CampagneCandidature
    {
        $c->loadMissing(['campagne', 'auditeur.inscriptionCourante']);
        $inscription = $c->auditeur?->inscriptionCourante;
        if ($inscription === null) {
            return $c->campagne;
        }

        return AnneeEnCours::promotionAccueil($inscription->annee_academique, $inscription->niveau) ?? $c->campagne;
    }

    /**
     * Cohorte eCampus du compte à créer : celle de la promotion d'accueil en M1. En M2 hors de sa promotion, aucune
     * cohorte n'est posée d'office (Q10 : le nom des cohortes d'option reste à fixer avec l'USI) : null.
     */
    public static function cohorte(Candidature $c): ?string
    {
        $c->loadMissing(['campagne', 'auditeur.inscriptionCourante']);
        $accueil = self::pour($c);
        if ($accueil === null || $c->campagne === null || $accueil->is($c->campagne)) {
            return $c->campagne === null ? null : ExportsRetenus::cohorteParDefaut($c->campagne);
        }

        return $c->auditeur?->inscriptionCourante?->niveau === 'M2' ? null : ExportsRetenus::cohorteParDefaut($accueil);
    }

    /** Promotion dont la lettre d'accès lit les paramètres : celle d'accueil pour un ancien réinscrit. */
    public static function lettreAcces(Candidature $c): ?CampagneCandidature
    {
        return $c->estReinscritAcma() ? self::pour($c) : $c->campagne;
    }
}
