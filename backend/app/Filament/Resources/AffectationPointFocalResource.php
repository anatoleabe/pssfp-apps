<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AffectationPointFocalResource\Pages;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\AffectationPointFocal;
use App\Models\Maquette;
use App\Models\Parcours;
use App\Models\User;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

/** Classes confiées aux points focaux (spec lot 16b) : ils y tiennent l'assiduité et le contrôle continu. */
#[BelongsToModule('lmd')]
class AffectationPointFocalResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = AffectationPointFocal::class;

    protected static ?string $slug = 'points-focaux';

    protected static ?string $modelLabel = 'Affectation';

    protected static ?string $pluralModelLabel = 'Points focaux';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Points focaux';

    protected static ?string $navigationGroup = GroupeNavigation::REGLAGES;

    protected static ?int $navigationSort = 40;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()->columns(2)
                ->description('Seuls les comptes portant le rôle « Point focal de classe » sont proposés. Une classe est un parcours pour une année académique.')
                ->schema([
                    Forms\Components\Select::make('user_id')->label('Point focal')->required()->searchable()->columnSpanFull()
                        ->options(fn (): array => User::role('point_focal')->orderBy('name')->pluck('name', 'id')->all())
                        ->rule(fn (): In => Rule::in(User::role('point_focal')->pluck('id')->all())),
                    Forms\Components\Select::make('annee_academique')->label('Année académique')->required()->native(false)
                        ->options(fn (): array => Maquette::query()->orderByDesc('annee_academique')->distinct()->pluck('annee_academique', 'annee_academique')->all())
                        ->rule(fn (): In => Rule::in(Maquette::query()->distinct()->pluck('annee_academique')->all())),
                    Forms\Components\Select::make('parcours_id')->label('Classe')->required()->native(false)
                        ->options(fn (): array => Parcours::query()->where('actif', true)->orderBy('niveau')->orderBy('ordre')->get()
                            ->mapWithKeys(fn (Parcours $p): array => [$p->id => $p->libelleComplet()])->all())
                        ->rule(fn (): In => Rule::in(Parcours::query()->where('actif', true)->pluck('id')->all()))
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Forms\Get $get): Unique => $rule
                            ->where('user_id', $get('user_id'))->where('annee_academique', $get('annee_academique'))),
                ]),
        ], ['unique' => 'Ce point focal est déjà affecté à cette classe pour cette année.', 'in' => 'Valeur non proposée dans la liste.']));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['pointFocal', 'parcours']))
            ->defaultSort('annee_academique', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('pointFocal.name')->label('Point focal')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('annee_academique')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('classe')->label('Classe')
                    ->getStateUsing(fn (AffectationPointFocal $r): string => (string) $r->parcours?->libelleComplet()),
                Tables\Columns\TextColumn::make('created_at')->label('Affecté le')->date('d/m/Y'),
            ])
            ->actions([Tables\Actions\DeleteAction::make()->label('Retirer')->modalHeading('Retirer ce point focal de la classe')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAffectationsPointsFocaux::route('/'),
            'create' => Pages\CreateAffectationPointFocal::route('/create'),
        ];
    }
}
