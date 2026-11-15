<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Candidature;
use App\Models\DeblocageAcces;
use App\Models\InscriptionAnnuelle;
use App\Services\Ecampus\ComptesEcampus;

/**
 * État de l'accès à eCampus d'un auditeur, du point de vue de son espace.
 *
 * Le compte, lui, existe indépendamment du paiement : un retard ne le suspend
 * pas, et un code déjà remis reste valable sur eCampus. La situation
 * financière, elle, ne change pas avec un déblocage : l'auditeur débloqué
 * reste « non soldé » tant qu'il n'a pas payé.
 */
final class AccesEcampus
{
    public const BLOQUE = 'bloque';

    public const EN_PREPARATION = 'en_preparation';

    public const DISPONIBLE = 'disponible';

    public const INDISPONIBLE = 'indisponible';

    /** Dans ces états, le compte existe et le mot de passe fonctionne. */
    private const COMPTE_EXISTANT = [ComptesEcampus::CREE, ComptesEcampus::EXISTANT, ComptesEcampus::COHORTE_A_FINIR];

    public static function etat(Candidature $c): string
    {
        if ($c->statut !== Candidature::STATUT_ACCEPTE || $c->withdrawn_at !== null) {
            return self::INDISPONIBLE;
        }
        if (! self::accesOuvert($c)) {
            return self::BLOQUE;
        }

        // Auditeurs importés des promotions antérieures : compte eCampus existant, mot de
        // passe inconnu du portail (ils gardent le leur) : l'accès est disponible sans lui.
        return self::compteExiste($c) && filled($c->matricule)
            ? self::DISPONIBLE
            : self::EN_PREPARATION;
    }

    public static function motDePasseConnu(Candidature $c): bool
    {
        return filled($c->moodle_password);
    }

    public static function compteExiste(Candidature $c): bool
    {
        return in_array($c->ecampus_statut, self::COMPTE_EXISTANT, true);
    }

    /**
     * L'accès s'ouvre quand les droits d'inscription de l'année courante sont
     * soldés, ou pendant un déblocage en cours (accès anticipé, lot A1). Tous
     * les chemins en découlent : état, codes remettables, lien et lettre.
     */
    public static function accesOuvert(Candidature $c): bool
    {
        $inscription = self::inscriptionCourante($c);

        return $inscription !== null && (self::deblocageValable($c) !== null || $inscription->situation()->inscriptionSoldee());
    }

    /**
     * Déblocage en cours alors que les droits ne sont pas soldés : l'accès est
     * accordé avant paiement, à régulariser avant l'échéance.
     */
    public static function accesAnticipe(Candidature $c): ?DeblocageAcces
    {
        $deblocage = self::deblocageValable($c);

        return $deblocage !== null && ! self::inscriptionCourante($c)->situation()->inscriptionSoldee() ? $deblocage : null;
    }

    /** Un auditeur exclu ou en abandon après son déblocage n'en profite plus : rien n'est payé. */
    private static function deblocageValable(Candidature $c): ?DeblocageAcces
    {
        $deblocage = self::inscriptionCourante($c)?->deblocageEnCours;

        return $deblocage !== null && ! in_array($c->auditeur?->statut, MessagesAuditeurs::STATUTS_EXCLUS, true) ? $deblocage : null;
    }

    public static function inscriptionCourante(Candidature $c): ?InscriptionAnnuelle
    {
        return $c->auditeur?->inscriptionCourante;
    }
}
