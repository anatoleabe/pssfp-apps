<?php

declare(strict_types=1);

namespace App\Filament\Resources\CampagneCandidatureResource\Pages;

use App\Filament\Resources\CampagneCandidatureResource;
use Filament\Resources\Pages\EditRecord;

class EditCampagneCandidature extends EditRecord
{
    protected static string $resource = CampagneCandidatureResource::class;

    protected function getHeaderActions(): array
    {
        // Pas de delete : FK RESTRICT bloque côté DB et la policy retourne false.
        return [];
    }
}
