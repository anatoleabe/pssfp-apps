<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Description affichable d'une passerelle.
 *
 * Ne contient DÉLIBÉRÉMENT aucun secret : `jetonConfigure` est un booléen,
 * jamais la valeur du jeton ni un fragment de celle-ci. Cet objet est rendu
 * tel quel dans l'administration.
 */
final class SmsConfigurationSummary
{
    public function __construct(
        /** Nom lisible de la passerelle, destiné à l'écran. */
        public readonly string $libelle,
        /** Expéditeur présenté au destinataire, ou null si non configuré. */
        public readonly ?string $expediteur,
        /** Un jeton ou une clé est présent en configuration. */
        public readonly bool $jetonConfigure,
        /** La passerelle envoie réellement, par opposition à la simulation. */
        public readonly bool $envoiReel,
    ) {}
}
