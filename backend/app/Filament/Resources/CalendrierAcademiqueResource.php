<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\CalendrierAcademiqueResource\Pages;
use App\Filament\Resources\CalendrierAcademiqueResource\RelationManagers\EvenementsRelationManager;
use App\Models\CalendrierAcademique;
use App\Support\BelongsToModule;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Calendrier académique et diagramme de Gantt (spec module 4, lot 12a ; D18 à D21). */
#[BelongsToModule('lmd')]
class CalendrierAcademiqueResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = CalendrierAcademique::class;

    protected static ?string $modelLabel = 'Calendrier académique';

    protected static ?string $pluralModelLabel = 'Calendriers académiques';

    protected static ?string $navigationLabel = 'Calendrier académique';

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 40;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['evenements' => fn ($q) => $q->where('type', '<>', 'phase')]))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('annee_academique')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('version')->label('Version'),
                Tables\Columns\TextColumn::make('periode')->label('Bornes')
                    ->getStateUsing(fn (CalendrierAcademique $c): string => $c->date_debut->format('d/m/Y').' – '.$c->date_fin->format('d/m/Y')),
                Tables\Columns\TextColumn::make('evenements_count')->label('Événements'),
                Tables\Columns\TextColumn::make('signataire')->label('Signé par')->placeholder('—')
                    ->description(fn (CalendrierAcademique $c): ?string => $c->signe_le?->format('d/m/Y')),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => CalendrierAcademique::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'publie' => 'success', 'signe' => 'info', 'soumis' => 'warning', 'remplace' => 'gray', default => 'gray'
                    }),
            ])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('libelle')->label('Calendrier')->getStateUsing(fn (CalendrierAcademique $c): string => $c->libelle()),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => CalendrierAcademique::STATUTS[$state] ?? $state),
                Infolists\Components\TextEntry::make('signature')->label('Signature')->placeholder('—')
                    ->getStateUsing(fn (CalendrierAcademique $c): ?string => $c->signataire ? $c->signataire.($c->signe_le ? ', le '.$c->signe_le->format('d/m/Y') : '') : null),
                Infolists\Components\TextEntry::make('renvoi_motif')->label('Motif du dernier renvoi')->placeholder('—'),
                Infolists\Components\TextEntry::make('acte_validation')->label('Acte d\'adoption')->placeholder('—')->columnSpan(2),
                Infolists\Components\TextEntry::make('reference_figee_at')->label('Référence figée le')->dateTime('d/m/Y H:i')->timezone('Africa/Douala')->placeholder('—'),
                Infolists\Components\TextEntry::make('publie_at')->label('Publié le')->dateTime('d/m/Y H:i')->timezone('Africa/Douala')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Diagramme de Gantt')->schema([
                Infolists\Components\ViewEntry::make('gantt')->hiddenLabel()->view('filament.lmd.gantt'),
            ]),
            Infolists\Components\Section::make('Contrôles, dépendances et journal')->collapsible()->schema([
                Infolists\Components\ViewEntry::make('controles')->hiddenLabel()->view('filament.lmd.controles-calendrier'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [EvenementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCalendriers::route('/'),
            'view' => Pages\ViewCalendrier::route('/{record}'),
        ];
    }
}
