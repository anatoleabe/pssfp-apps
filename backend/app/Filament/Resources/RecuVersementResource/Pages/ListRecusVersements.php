<?php

declare(strict_types=1);

namespace App\Filament\Resources\RecuVersementResource\Pages;

use App\Filament\Resources\RecuVersementResource;
use Filament\Resources\Pages\ListRecords;

class ListRecusVersements extends ListRecords
{
    protected static string $resource = RecuVersementResource::class;

    public function getSubheading(): ?string
    {
        return 'Un reçu se délivre depuis l\'onglet Versements de la fiche de l\'auditeur. Il ne se modifie jamais : une erreur se corrige par une écriture d\'annulation.';
    }
}
