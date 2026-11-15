<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\ReglementOrganisme;

/** Issue d'un règlement réparti (lot A2, §9.7) : le règlement, ses versements, et les messages des accès. */
final readonly class ResultatReglement
{
    /**
     * @param  int  $versements  versements créés, un par part positive
     * @param  int  $prevenus  auditeurs prévenus de leurs accès à cette occasion
     * @param  int  $echecs  envois des accès qui ont échoué (à renvoyer depuis la fiche)
     */
    public function __construct(
        public ReglementOrganisme $reglement,
        public int $versements,
        public int $prevenus,
        public int $echecs,
    ) {}
}
