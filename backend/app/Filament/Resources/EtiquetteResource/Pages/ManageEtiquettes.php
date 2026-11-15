<?php

declare(strict_types=1);

namespace App\Filament\Resources\EtiquetteResource\Pages;

use App\Filament\Resources\EtiquetteResource;
use Filament\Resources\Pages\ManageRecords;

class ManageEtiquettes extends ManageRecords
{
    protected static string $resource = EtiquetteResource::class;

    public function getSubheading(): ?string
    {
        return 'Les étiquettes se créent et se posent depuis la fiche d\'un auditeur, ou sur une sélection de la liste des auditeurs.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
