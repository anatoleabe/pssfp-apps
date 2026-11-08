<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProgrammationEnseignantResource\Pages;

use App\Filament\Resources\ProgrammationEnseignantResource;
use Filament\Resources\Pages\ListRecords;

class ListProgrammationsEnseignant extends ListRecords
{
    protected static string $resource = ProgrammationEnseignantResource::class;

    public function getTitle(): string
    {
        return ProgrammationEnseignantResource::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return ProgrammationEnseignantResource::enseignantSeul()
            ? 'Les cours que la Scolarité vous confie, période par période. Acceptez ou déclinez chaque programmation notifiée.'
            : null;
    }
}
