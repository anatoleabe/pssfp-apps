<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\DeblocageAcces;

/** Issue d'un déblocage accordé, et du message des accès éventuellement envoyé. */
final readonly class ResultatDeblocage
{
    /** @param  bool|null  $notification  true envoyé, false échec, null rien à envoyer (compte à créer, déjà prévenu) */
    public function __construct(
        public DeblocageAcces $deblocage,
        public ?bool $notification,
    ) {}
}
