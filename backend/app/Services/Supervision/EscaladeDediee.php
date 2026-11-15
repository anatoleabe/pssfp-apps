<?php

declare(strict_types=1);

namespace App\Services\Supervision;

/**
 * Contrôle dont l'escalade (niveau 2) ne va pas au responsable de scolarité du barème
 * commun mais à un rôle propre : un défaut technique relève de l'USI, puis du super
 * administrateur (CPT_COHERENCE, décision du 15/11/2026). Le niveau 3 reste la direction.
 */
interface EscaladeDediee
{
    /** Rôle relancé en escalade (niveau 2). */
    public function escalade(): string;
}
