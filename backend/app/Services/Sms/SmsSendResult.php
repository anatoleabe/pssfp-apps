<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Ce qu'une passerelle rapporte après un envoi réussi.
 *
 * `send()` ne renvoie rien et reste le contrat principal : tout le code
 * existant continue de l'utiliser sans modification. Les appelants qui ont
 * besoin de tracer l'envoi passent par ReportsSmsDelivery.
 */
final class SmsSendResult
{
    public function __construct(
        /** Sender ID ou numéro émetteur réellement présenté au destinataire. */
        public readonly ?string $expediteur,
        /** Code applicatif rendu par la passerelle (Echo SMS : 1016, 1015…). */
        public readonly ?string $codeFournisseur,
        /** La passerelle signale une livraison partielle (code 1015). */
        public readonly bool $partiel = false,
        /** Identifiant du message chez la passerelle, s'il en fournit un. */
        public readonly ?string $messageId = null,
        /** Statut rendu à l'envoi, brut — jamais normalisé ici. */
        public readonly ?string $statut = null,
        /** Coût facturé, tel que rendu par la passerelle. */
        public readonly ?string $cout = null,
    ) {}
}
