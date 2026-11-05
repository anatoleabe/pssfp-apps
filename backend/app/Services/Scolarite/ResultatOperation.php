<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Versement;

/** Issue d'une écriture du registre, et du SMS éventuellement déclenché. */
final readonly class ResultatOperation
{
    /** @param  bool|null  $sms  true envoyé, false échec, null rien à envoyer */
    public function __construct(
        public ?Versement $versement,
        public ?bool $sms,
    ) {}
}
