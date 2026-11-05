<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Services\Ecampus\ComptesEcampus;

/**
 * État de l'accès à eCampus d'un auditeur, du point de vue de son espace.
 *
 * Le compte, lui, existe indépendamment du paiement : un retard ne le suspend
 * pas, et un code déjà remis reste valable sur eCampus.
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
        if (! self::inscriptionSoldee($c)) {
            return self::BLOQUE;
        }

        return self::compteExiste($c) && filled($c->matricule) && filled($c->moodle_password)
            ? self::DISPONIBLE
            : self::EN_PREPARATION;
    }

    public static function compteExiste(Candidature $c): bool
    {
        return in_array($c->ecampus_statut, self::COMPTE_EXISTANT, true);
    }

    public static function inscriptionSoldee(Candidature $c): bool
    {
        return self::inscriptionCourante($c)?->situation()->inscriptionSoldee() ?? false;
    }

    public static function inscriptionCourante(Candidature $c): ?InscriptionAnnuelle
    {
        return $c->auditeur?->inscriptionCourante;
    }
}
