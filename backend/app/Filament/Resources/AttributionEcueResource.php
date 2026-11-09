<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AttributionEcueResource\Pages;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\AttributionEcue;
use App\Models\MaquetteEcue;
use App\Models\User;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/** ECUE confiés aux enseignants : ils en saisissent le CC et l'assiduité (spec lot 5, § 3.9). */
#[BelongsToModule('lmd')]
class AttributionEcueResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = AttributionEcue::class;

    protected static ?string $modelLabel = 'Attribution';

    protected static ?string $pluralModelLabel = 'Attributions des ECUE';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Attributions des ECUE';

    protected static ?string $navigationGroup = GroupeNavigation::REFERENTIEL;

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()->columns(2)
                ->description('Seuls les comptes portant le rôle « Enseignant » sont proposés.')
                ->schema([
                    Forms\Components\Select::make('maquette_ecue_id')->label('ECUE')->required()->searchable()->columnSpanFull()
                        ->options(fn (): array => MaquetteEcue::query()
                            ->whereHas('maquetteUe.maquette', fn ($q) => $q->where('statut', '!=', 'archivee'))
                            ->with('maquetteUe.maquette.parcours')->orderBy('code')->get()
                            ->mapWithKeys(fn (MaquetteEcue $e): array => [$e->id => $e->libelle().' ('.$e->maquetteUe->maquette->libelle().')'])->all()),
                    Forms\Components\Select::make('user_id')->label('Enseignant')->required()->searchable()
                        ->options(fn (): array => User::role('enseignant')->orderBy('name')->pluck('name', 'id')->all())
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Forms\Get $get): Unique => $rule->where('maquette_ecue_id', $get('maquette_ecue_id'))),
                    Forms\Components\Select::make('role')->label('Rôle')->required()->native(false)->options(AttributionEcue::ROLES)->default('responsable'),
                ]),
        ], ['unique' => 'Cet ECUE est déjà attribué à cet enseignant.']));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ecue.maquetteUe.maquette.parcours', 'enseignant']))
            ->columns([
                Tables\Columns\TextColumn::make('enseignant.name')->label('Enseignant')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('ecue.code')->label('ECUE')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('ecue.intitule')->label('Intitulé')->wrap(),
                Tables\Columns\TextColumn::make('maquette')->label('Maquette')
                    ->getStateUsing(fn (AttributionEcue $r): string => (string) $r->ecue?->maquetteUe?->maquette?->libelle()),
                Tables\Columns\TextColumn::make('role')->label('Rôle')->badge()->formatStateUsing(fn (string $state): string => AttributionEcue::ROLES[$state] ?? $state),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttributionsEcue::route('/'),
            'create' => Pages\CreateAttributionEcue::route('/create'),
            'edit' => Pages\EditAttributionEcue::route('/{record}/edit'),
        ];
    }
}
