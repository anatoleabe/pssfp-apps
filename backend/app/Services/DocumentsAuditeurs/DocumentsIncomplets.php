<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

/** Paramètres de la campagne à compléter avant de générer un document. */
final class DocumentsIncomplets extends DocumentImpossible
{
    /** @param  list<string>  $manquants */
    public function __construct(public readonly array $manquants)
    {
        parent::__construct('À compléter sur la campagne (section « Documents des auditeurs ») : '.implode(', ', $manquants).'.');
    }
}
