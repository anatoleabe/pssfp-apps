<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\Candidature;
use App\Services\Scolarite\AccesEcampus;

/**
 * Droits d'inscription des auditeurs : soldés selon le registre des versements
 * (`App\Services\Scolarite`), ils conditionnent la remise du code d'accès à la
 * plateforme. La lettre d'admission signée, elle, reste visible sans paiement.
 * Distincts des frais de dossier (`frais_paye`).
 */
final class FraisInscription
{
    public static function concerne(Candidature $c): bool
    {
        return $c->statut === Candidature::STATUT_ACCEPTE && $c->withdrawn_at === null;
    }

    public static function payes(Candidature $c): bool
    {
        return AccesEcampus::inscriptionSoldee($c);
    }
}
