<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemandeAutorisationResource\Pages;

use App\Filament\Resources\DemandeAutorisationResource;
use App\Models\User;
use App\Services\Messagerie\Demandes;
use App\Services\Supervision\Supervision;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDemandes extends ListRecords
{
    protected static string $resource = DemandeAutorisationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nouvelle demande')->icon('heroicon-o-plus')];
    }

    public function getTabs(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $tabs = [
            'a_decider' => Tab::make('À décider')->icon('heroicon-o-scale')->badge(DemandeAutorisationResource::getNavigationBadge())
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey(app(Demandes::class)->aDecider($user)->pluck('id'))),
            'mes_demandes' => Tab::make('Mes demandes')->icon('heroicon-o-user')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('demandeur_id', $user->id)),
        ];
        if (Supervision::peutConsulter($user)) {
            $tabs['toutes'] = Tab::make('Toutes')->icon('heroicon-o-queue-list');
        }

        return $tabs;
    }
}
