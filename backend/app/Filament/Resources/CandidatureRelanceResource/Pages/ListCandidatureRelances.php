<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureRelanceResource\Pages;

use App\Filament\Resources\CandidatureRelanceResource;
use App\Models\CandidatureRelance;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCandidatureRelances extends ListRecords
{
    protected static string $resource = CandidatureRelanceResource::class;

    protected function getHeaderActions(): array
    {
        // Journal en lecture seule : aucune action de création.
        return [];
    }

    /**
     * Onglets d'accès rapide. « Échecs » en premier après « Tous » : c'est
     * l'entrée utile quand on ouvre cet écran pour comprendre un problème.
     */
    public function getTabs(): array
    {
        return [
            'tous' => Tab::make('Tous'),

            'echecs' => Tab::make('Échecs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('statut', CandidatureRelance::STATUT_ECHEC))
                ->badge(CandidatureRelance::query()->where('statut', CandidatureRelance::STATUT_ECHEC)->count())
                ->badgeColor('danger'),

            'sms' => Tab::make('SMS')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('canal', CandidatureRelance::CANAL_SMS)),

            'email' => Tab::make('E-mails')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('canal', CandidatureRelance::CANAL_EMAIL)),
        ];
    }
}
