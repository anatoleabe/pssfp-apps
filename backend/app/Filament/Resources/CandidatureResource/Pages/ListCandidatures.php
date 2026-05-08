<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource\Pages;

use App\Filament\Resources\CandidatureResource;
use Filament\Resources\Pages\ListRecords;

class ListCandidatures extends ListRecords
{
    protected static string $resource = CandidatureResource::class;

    protected function getHeaderActions(): array
    {
        // Pas de bouton "Nouvelle candidature" — la création est interdite
        // côté admin (cf. arbitrage D PR D : un dossier ne peut être créé
        // que par le candidat authentifié via /v1/applications/me).
        return [];
    }
}
