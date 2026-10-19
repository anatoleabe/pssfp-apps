<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/** Rapport qui consigne les redirections écrites par RedirectWriter (biblio:import, biblio:import-orphans). */
interface RedirectRecorder
{
    /** @param  'created'|'existing'|'conflicts'  $outcome */
    public function redirect(LegacyPath $path, string $outcome, string $target, ?string $existingTarget = null): void;
}
