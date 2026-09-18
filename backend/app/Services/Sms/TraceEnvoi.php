<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Ce qu'on inscrit au journal après une tentative d'envoi.
 *
 * Regroupe des champs qui, passés un à un, porteraient `tracer()` à onze
 * paramètres positionnels. Deux constructeurs nommés plutôt qu'un seul :
 * un e-mail n'a ni identifiant de message ni coût, et rien ne doit laisser
 * croire le contraire.
 */
final class TraceEnvoi
{
    private function __construct(
        public readonly ?string $expediteur,
        public readonly ?string $codeFournisseur,
        public readonly ?string $messageUid,
        public readonly ?string $statutLivraison,
        public readonly ?string $cout,
    ) {}

    public static function sms(
        ?string $expediteur,
        ?string $codeFournisseur = null,
        ?string $messageUid = null,
        ?string $statutLivraison = null,
        ?string $cout = null,
    ): self {
        return new self($expediteur, $codeFournisseur, $messageUid, $statutLivraison, $cout);
    }

    public static function email(?string $expediteur): self
    {
        return new self($expediteur, null, null, null, null);
    }

    public static function depuisResultat(SmsSendResult $resultat): self
    {
        return new self(
            $resultat->expediteur,
            $resultat->codeFournisseur,
            $resultat->messageId,
            $resultat->statut,
            $resultat->cout,
        );
    }
}
