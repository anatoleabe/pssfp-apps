<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\IndisponibiliteEnseignantResource\Pages;
use App\Models\IndisponibiliteEnseignant;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\Programmations;
use App\Support\BelongsToModule;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Indisponibilités des enseignants (lot 12d ; D22) : aucune séance programmée pendant. L'enseignant gère les siennes. */
#[BelongsToModule('lmd')]
class IndisponibiliteEnseignantResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = IndisponibiliteEnseignant::class;

    protected static ?string $modelLabel = 'Indisponibilité';

    protected static ?string $pluralModelLabel = 'Indisponibilités des enseignants';

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = GroupeNavigation::PLANIFICATION;

    protected static ?string $breadcrumb = 'Indisponibilités';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'indisponibilites-enseignant';

    public static function gestion(): bool
    {
        return (bool) auth()->user()?->can(PermissionsLmd::PROGRAMMATION);
    }

    public static function getNavigationLabel(): string
    {
        return static::gestion() ? 'Indisponibilités des enseignants' : 'Mes indisponibilités';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(! static::gestion(), fn (Builder $q) => $q->where('enseignant_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('enseignant'))
            ->defaultSort('debut', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('enseignant.name')->label('Enseignant')->searchable()->hidden(fn (): bool => ! static::gestion()),
                Tables\Columns\TextColumn::make('debut')->label('Du')->dateTime('d/m/Y H:i', 'Africa/Douala'),
                Tables\Columns\TextColumn::make('fin')->label('Au')->dateTime('d/m/Y H:i', 'Africa/Douala'),
                Tables\Columns\TextColumn::make('motif')->label('Motif')->placeholder('—')->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('supprimer')->label('Supprimer')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                    ->visible(fn (IndisponibiliteEnseignant $i): bool => (bool) auth()->user()?->can('delete', $i))
                    ->action(function (IndisponibiliteEnseignant $i): void {
                        app(Programmations::class)->supprimerIndisponibilite($i, auth()->user());
                        Notification::make()->success()->title('Indisponibilité supprimée.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageIndisponibilitesEnseignant::route('/')];
    }
}
