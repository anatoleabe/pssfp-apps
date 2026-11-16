<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

/** Une étape de `acma:reprendre` (spec du lot 15, §10.3). Elle lit le transit et l'archive en place. */
interface Etape
{
    public function nom(): string;

    /** @return array<string, int|array<string, int>> comptes de l'étape, sans donnée nominative */
    public function executer(ContexteReprise $contexte): array;
}
