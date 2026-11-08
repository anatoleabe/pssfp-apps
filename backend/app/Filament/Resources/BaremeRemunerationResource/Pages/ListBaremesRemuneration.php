<?php

declare(strict_types=1);

namespace App\Filament\Resources\BaremeRemunerationResource\Pages;

use App\Filament\Resources\BaremeRemunerationResource;
use Filament\Resources\Pages\ListRecords;

class ListBaremesRemuneration extends ListRecords
{
    protected static string $resource = BaremeRemunerationResource::class;

    public function getSubheading(): ?string
    {
        return 'Seule une ligne validée paie. TD et TP à 0 FCFA : les heures sont tracées sur l\'état sans être payées (D24).';
    }
}
