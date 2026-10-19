<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Fiche en lecture seule : pour qui peut voir le document sans pouvoir le
 * modifier (consultation, document publié sans droit de publier, dépôt d'un
 * contributeur une fois soumis). L'historique et les anciennes adresses
 * s'affichent dessous, selon les droits de chacun.
 */
class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        // Autorisée par la Policy (`update`) : absente pour la consultation.
        return [Actions\EditAction::make()];
    }
}
