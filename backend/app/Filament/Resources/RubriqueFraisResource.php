<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\RubriqueFraisResource\Pages;
use App\Models\BaremeScolarite;
use App\Models\RubriqueFrais;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\RubriquesFrais;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Finances > Rubriques (reçu A4, R2) : les frais que couvre le barème de chaque année et niveau, leur code d'imputation et
 * leur ordre de répartition. Lecture par l'espace Finances ; modification par l'administrateur seulement
 * (`finances.rubriques_gerer`), journalisée. Une rubrique ne se supprime pas : elle se désactive.
 */
#[BelongsToModule('scolarite')]
class RubriqueFraisResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = RubriqueFrais::class;

    protected static ?string $modelLabel = 'Rubrique de frais';

    protected static ?string $pluralModelLabel = 'Rubriques de frais';

    protected static ?string $navigationLabel = 'Rubriques';

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = GroupeNavigation::FINANCES;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'finances/rubriques';

    public static function canViewAny(): bool
    {
        return EspaceFinances::peutConsulter(auth()->user());
    }

    public static function canCreate(): bool
    {
        return RubriquesFrais::peutGerer(auth()->user());
    }

    public static function canEdit(Model $record): bool
    {
        return RubriquesFrais::peutGerer(auth()->user());
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('annee_academique')->label('Année académique')->required()->regex('/^\d{4}-\d{4}$/')->placeholder('2026-2027')->maxLength(9),
                Forms\Components\Select::make('niveau')->label('Niveau')->required()->options(BaremeScolarite::NIVEAUX)->native(false),
                Forms\Components\TextInput::make('libelle')->label('Libellé')->required()->maxLength(120)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Forms\Get $get): Unique => $rule->where('annee_academique', $get('annee_academique'))->where('niveau', $get('niveau'))),
                Forms\Components\TextInput::make('montant')->label('Montant')->required()->integer()->minValue(0)->maxValue(100000000)->suffix('FCFA'),
                Forms\Components\TextInput::make('ordre')->label('Ordre de répartition')->required()->integer()->minValue(1)->maxValue(99)
                    ->helperText('Un versement remplit les rubriques dans cet ordre : frais fixes d\'abord, pension en dernier.')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Forms\Get $get): Unique => $rule->where('annee_academique', $get('annee_academique'))->where('niveau', $get('niveau'))),
                Forms\Components\Toggle::make('compte_selon_promotion')->label('Compte selon la promotion (pension)')->live()
                    ->helperText('Le code imputé est le compte de scolarité de la promotion de l\'auditeur.'),
                Forms\Components\TextInput::make('code_imputation')->label('Code d\'imputation')->maxLength(40)
                    ->regex('/^\d{6}$/')->notIn([RubriquesFrais::COMPTE_INTERDIT])->placeholder('À préciser')
                    ->hidden(fn (Forms\Get $get): bool => (bool) $get('compte_selon_promotion'))->dehydrateStateUsing(fn (?string $state, Forms\Get $get): ?string => $get('compte_selon_promotion') ? null : $state),
                Forms\Components\Toggle::make('actif')->label('Active')->default(true)
                    ->helperText('Une rubrique ne se supprime pas : désactivée, elle ne sert plus aux reçus à venir.'),
            ]),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('ordre')
            ->defaultGroup(Group::make('couple')->label('Année et niveau')
                ->getKeyFromRecordUsing(fn (RubriqueFrais $r): string => $r->annee_academique.' '.$r->niveau)
                ->getTitleFromRecordUsing(fn (RubriqueFrais $r): string => $r->annee_academique.' — '.(BaremeScolarite::NIVEAUX[$r->niveau] ?? $r->niveau))
                ->orderQueryUsing(fn ($query, string $direction) => $query->orderBy('annee_academique', 'desc')->orderBy('niveau')))
            ->columns([
                Tables\Columns\TextColumn::make('ordre')->label('Ordre'),
                Tables\Columns\TextColumn::make('libelle')->label('Rubrique'),
                Tables\Columns\TextColumn::make('code_imputation')->label('Imputation')
                    ->getStateUsing(fn (RubriqueFrais $r): string => $r->compte_selon_promotion ? 'Compte de la promotion' : ($r->code_imputation ?? 'À préciser')),
                Tables\Columns\TextColumn::make('montant')->label('Montant')->alignEnd()->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state)),
                Tables\Columns\IconColumn::make('actif')->label('Active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRubriquesFrais::route('/'),
            'create' => Pages\CreateRubriqueFrais::route('/create'),
            'edit' => Pages\EditRubriqueFrais::route('/{record}/edit'),
        ];
    }
}
