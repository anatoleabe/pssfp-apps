<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

/**
 * Rendu Word (.docx) du communiqué des résultats et du PV du jury,
 * modifiable avant impression et signature.
 */
final class ResultatsSelectionWord
{
    public function __construct(
        private readonly CommuniqueWord $communique,
        private readonly PvWord $pv,
    ) {}

    public function communique(ResultatsSelection $resultats): string
    {
        return $this->communique->generer($resultats);
    }

    public function pv(ResultatsSelection $resultats): string
    {
        return $this->pv->generer($resultats);
    }
}
