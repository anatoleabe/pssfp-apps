<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\PlanningExamensResource\Pages;
use App\Filament\Resources\PlanningExamensResource\RelationManagers\EpreuvesRelationManager;
use App\Models\PlanningExamens;
use App\Support\BelongsToModule;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Plannings des sessions d'examens (spec module 4, lot 27). */
#[BelongsToModule('lmd')]
class PlanningExamensResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = PlanningExamens::class;

    protected static ?string $slug = 'plannings-examens';

    protected static ?string $modelLabel = 'Planning d\'examens';

    protected static ?string $pluralModelLabel = 'Plannings d\'examens';

    protected static ?string $navigationLabel = 'Planning d\'examens';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = GroupeNavigation::PLANIFICATION;

    protected static ?int $navigationSort = 41;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('session.calendrier')->withCount('epreuves'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('session.libelle')->label('Session')->wrap()
                    ->description(fn (PlanningExamens $p): string => $p->session->debut_prevu->format('d/m/Y').' – '.$p->session->fin_prevue->format('d/m/Y')),
                Tables\Columns\TextColumn::make('session.niveau')->label('Niveau')->placeholder('Tous'),
                Tables\Columns\TextColumn::make('session.calendrier.annee_academique')->label('Année'),
                Tables\Columns\TextColumn::make('epreuves_count')->label('Épreuves'),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => PlanningExamens::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => $state === PlanningExamens::PUBLIE ? 'success' : 'gray'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('statut')->label('État')->options(PlanningExamens::STATUTS)])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('session.libelle')->label('Session')->columnSpan(2)
                    ->helperText(fn (PlanningExamens $p): string => 'Du '.$p->session->debut_prevu->format('d/m/Y').' au '.$p->session->fin_prevue->format('d/m/Y')),
                Infolists\Components\TextEntry::make('session.niveau')->label('Niveau')->placeholder('Tous les niveaux')
                    ->helperText(fn (PlanningExamens $p): string => $p->estRattrapage() ? 'Rattrapage : seuls les éligibles composent.' : 'Session normale'),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => PlanningExamens::STATUTS[$state] ?? $state)
                    ->helperText(fn (PlanningExamens $p): ?string => $p->publie_at?->timezone('Africa/Douala')->format('\p\u\b\l\i\é \l\e d/m/Y à H:i')),
            ]),
            Infolists\Components\Section::make('Contrôles et répartition en salles')->collapsible()->schema([
                Infolists\Components\ViewEntry::make('controles')->hiddenLabel()->view('filament.lmd.planning-examens'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [EpreuvesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlanningsExamens::route('/'),
            'view' => Pages\ViewPlanningExamens::route('/{record}'),
        ];
    }
}
