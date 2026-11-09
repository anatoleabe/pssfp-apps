<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\EmploiDuTempsResource\Pages;
use App\Filament\Resources\EmploiDuTempsResource\RelationManagers\SeancesRelationManager;
use App\Models\EmploiDuTemps;
use App\Support\BelongsToModule;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Emplois du temps des périodes de cours (spec module 4, lot 12c ; D21). */
#[BelongsToModule('lmd')]
class EmploiDuTempsResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = EmploiDuTemps::class;

    protected static ?string $modelLabel = 'Emploi du temps';

    protected static ?string $pluralModelLabel = 'Emplois du temps';

    protected static ?string $navigationLabel = 'Emplois du temps';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = GroupeNavigation::PLANIFICATION;

    protected static ?int $navigationSort = 40;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['periode.calendrier', 'parcours'])
                ->withCount(['seances' => fn ($q) => $q->whereIn('statut', ['prevue', 'tenue'])]))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('parcours.code')->label('Parcours')->description(fn (EmploiDuTemps $e): string => $e->parcours->niveau),
                Tables\Columns\TextColumn::make('periode.libelle')->label('Période de cours')->wrap()
                    ->description(fn (EmploiDuTemps $e): string => $e->periode->debut_prevu->format('d/m/Y').' – '.$e->periode->fin_prevue->format('d/m/Y')),
                Tables\Columns\TextColumn::make('periode.calendrier.annee_academique')->label('Année'),
                Tables\Columns\TextColumn::make('seances_count')->label('Séances'),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => EmploiDuTemps::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => $state === EmploiDuTemps::PUBLIE ? 'success' : 'gray'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('statut')->label('État')->options(EmploiDuTemps::STATUTS)])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('parcours.libelle')->label('Parcours')
                    ->helperText(fn (EmploiDuTemps $e): string => $e->parcours->code.' — '.$e->parcours->niveau),
                Infolists\Components\TextEntry::make('periode.libelle')->label('Période de cours')->columnSpan(2)
                    ->helperText(fn (EmploiDuTemps $e): string => 'Du '.$e->periode->debut_prevu->format('d/m/Y').' au '.$e->periode->fin_prevue->format('d/m/Y')),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => EmploiDuTemps::STATUTS[$state] ?? $state)
                    ->helperText(fn (EmploiDuTemps $e): ?string => $e->publie_at?->timezone('Africa/Douala')->format('\p\u\b\l\i\é \l\e d/m/Y à H:i')),
            ]),
            Infolists\Components\Section::make('Conflits et volume horaire')->collapsible()->schema([
                Infolists\Components\ViewEntry::make('controles')->hiddenLabel()->view('filament.lmd.emploi-du-temps'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [SeancesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmploisDuTemps::route('/'),
            'view' => Pages\ViewEmploiDuTemps::route('/{record}'),
        ];
    }
}
