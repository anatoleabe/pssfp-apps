<?php

declare(strict_types=1);

namespace App\Filament\Resources\ErreurTechniqueResource\Pages;

use App\Filament\Resources\ErreurTechniqueResource;
use Filament\Resources\Pages\ListRecords;

class ListErreursTechniques extends ListRecords
{
    protected static string $resource = ErreurTechniqueResource::class;

    public function getSubheading(): ?string
    {
        return 'Heures de Yaoundé. Pannes de l\'API, du back-office et des tâches de fond, et écrans d\'erreur vus sur le portail apply. Conservées 90 jours. Le détail technique complet reste dans le journal fichier du serveur (identifiant de requête).';
    }
}
