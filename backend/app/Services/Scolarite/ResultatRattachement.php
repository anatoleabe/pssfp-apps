<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

/** Issue d'un rattachement d'auditeurs à un engagement (lot A2, §9.5) : chaque auditeur est traité séparément. */
final readonly class ResultatRattachement
{
    /**
     * @param  list<string>  $rattaches  matricules rattachés
     * @param  array<string, string>  $refus  matricule => motif du refus
     * @param  int  $prevenus  auditeurs prévenus de leurs accès (SMS ou e-mail parti)
     * @param  int  $echecs  envois des accès qui ont échoué (à renvoyer depuis la fiche)
     */
    public function __construct(
        public array $rattaches,
        public array $refus,
        public int $prevenus,
        public int $echecs,
    ) {}
}
