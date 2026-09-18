<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Résultat d'un contrôle de connexion à la passerelle.
 *
 * Ne contient DÉLIBÉRÉMENT aucun secret. TechSoft renvoie le jeton API en
 * clair dans `GET /user` : seuls le nom du compte et le solde sont extraits,
 * la réponse brute n'est ni conservée ni journalisée.
 */
final class ConnectivityReport
{
    public function __construct(
        public readonly bool $joignable,
        public readonly ?string $compte,
        public readonly ?string $solde,
        public readonly ?string $erreur,
    ) {}
}
