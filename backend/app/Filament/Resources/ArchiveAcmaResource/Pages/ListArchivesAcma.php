<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArchiveAcmaResource\Pages;

use App\Filament\Resources\ArchiveAcmaResource;
use App\Filament\Resources\ArchiveAcmaResource\ArchiveAcmaActions;
use Filament\Resources\Pages\ListRecords;

/** Liste des dossiers d'archive ACMA, et téléchargement des classeurs de la reprise (privés, journalisés). */
class ListArchivesAcma extends ListRecords
{
    protected static string $resource = ArchiveAcmaResource::class;

    public function getSubheading(): ?string
    {
        return 'Dossiers repris d\'ACMA (2013-2014 à 2025-2026), en lecture seule. Visibles de l\'administration seule.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ArchiveAcmaActions::classeur('classeurRapprochement', 'Cas à arbitrer (classeur)', 'rapprochement-', ArchiveAcmaActions::PERMISSION_CLASSEUR_RAPPROCHEMENT),
            ArchiveAcmaActions::classeur('classeurEcarts', 'Écarts 2026-2027 (classeur)', 'ecarts-2026-2027-', ArchiveAcmaActions::PERMISSION_CLASSEUR_ECARTS),
        ];
    }
}
