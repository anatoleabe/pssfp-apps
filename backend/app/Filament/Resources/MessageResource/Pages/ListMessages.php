<?php

declare(strict_types=1);

namespace App\Filament\Resources\MessageResource\Pages;

use App\Filament\Resources\MessageResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMessages extends ListRecords
{
    protected static string $resource = MessageResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nouveau message')->icon('heroicon-o-pencil-square')];
    }

    public function getTabs(): array
    {
        $id = (int) auth()->id();
        $recu = fn (Builder $q, callable $etat): Builder => $q->whereHas('destinataires', fn (Builder $d) => $etat($d->where('user_id', $id)));

        return [
            'recus' => Tab::make('Reçus')->icon('heroicon-o-inbox')
                ->modifyQueryUsing(fn (Builder $query) => $recu($query, fn (Builder $d) => $d->whereNull('archive_le'))),
            'non_lus' => Tab::make('Non lus')->icon('heroicon-o-envelope')->badge(MessageResource::getNavigationBadge())
                ->modifyQueryUsing(fn (Builder $query) => $recu($query, fn (Builder $d) => $d->whereNull('archive_le')->whereNull('lu_le'))),
            'envoyes' => Tab::make('Envoyés')->icon('heroicon-o-paper-airplane')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('expediteur_id', $id)),
            'archives' => Tab::make('Archivés')->icon('heroicon-o-archive-box')
                ->modifyQueryUsing(fn (Builder $query) => $recu($query, fn (Builder $d) => $d->whereNotNull('archive_le'))),
        ];
    }
}
