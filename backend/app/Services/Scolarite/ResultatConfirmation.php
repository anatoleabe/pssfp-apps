<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\ConfirmationVersement;

/** Issue d'une confirmation par la scolarité, et du SMS des accès qu'elle a pu déclencher en soldant les droits. */
final readonly class ResultatConfirmation
{
    /**
     * @param  list<ConfirmationVersement>  $confirmations  une, ou une par part d'un règlement d'organisme
     * @param  bool|null  $sms  true envoyé, false échec, null rien à envoyer (ou transaction de l'appelant encore ouverte)
     */
    public function __construct(
        public array $confirmations,
        public ?bool $sms,
    ) {}
}
