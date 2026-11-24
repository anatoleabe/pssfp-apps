<?php

declare(strict_types=1);

namespace App\Filament\Resources\VirementAnnonceResource\Pages;

use App\Filament\Resources\VirementAnnonceResource;
use App\Filament\Resources\VirementAnnonceResource\Widgets\VirementsSansRecu;
use App\Models\VirementAnnonce;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListVirementsAnnonces extends ListRecords
{
    protected static string $resource = VirementAnnonceResource::class;

    public function getTabs(): array
    {
        return [
            'en_attente' => Tab::make('En attente de crédit')->badge(VirementAnnonceResource::getNavigationBadge())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('statut', VirementAnnonce::EN_ATTENTE)),
            'valides' => Tab::make('Validés')->modifyQueryUsing(fn (Builder $query) => $query->where('statut', VirementAnnonce::VALIDE)),
            'abandonnes' => Tab::make('Abandonnés')->modifyQueryUsing(fn (Builder $query) => $query->where('statut', VirementAnnonce::ABANDONNE)),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [VirementsSansRecu::class];
    }
}
