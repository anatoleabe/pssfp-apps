<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalActiviteResource\Pages;

use App\Filament\Resources\JournalActiviteResource;
use Filament\Resources\Pages\ListRecords;

class ListJournalActivite extends ListRecords
{
    protected static string $resource = JournalActiviteResource::class;

    public function getSubheading(): ?string
    {
        return 'Heures de Yaoundé. Les entrées antérieures au 26/10/2026 à 15h39 peuvent être décalées d\'une à deux heures (fuseau du serveur corrigé ce jour-là).';
    }
}
