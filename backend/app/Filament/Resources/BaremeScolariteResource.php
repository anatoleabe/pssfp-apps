<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\BaremeScolariteResource\Pages;
use App\Models\BaremeScolarite;
use App\Services\Scolarite\LibelleSituation;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Vie académique > Barèmes : une ligne par année académique et par niveau.
 * Une inscription copie les montants à sa création : modifier un barème ne
 * change rien pour les auditeurs déjà inscrits. Les échéances, elles, se lisent
 * en direct.
 */
#[BelongsToModule('scolarite')]
class BaremeScolariteResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = BaremeScolarite::class;

    protected static ?string $modelLabel = 'Barème';

    protected static ?string $pluralModelLabel = 'Barèmes';

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Vie académique';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        $montant = fn (string $champ, string $libelle): Forms\Components\TextInput => Forms\Components\TextInput::make($champ)
            ->label($libelle)->required()->integer()->minValue(0)->maxValue(100000000)->suffix('FCFA');

        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()
                ->description(fn (?BaremeScolarite $record): ?string => $record?->inscriptions()->exists()
                    ? 'Ce barème est déjà utilisé : une modification des montants ne s\'applique qu\'aux inscriptions créées ensuite. Les échéances, elles, valent pour tous.'
                    : null)
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('annee_academique')->label('Année académique')->required()
                        ->regex('/^(\d{4})-(\d{4})$/')->placeholder('2026-2027')->maxLength(9)
                        ->rule(fn (): \Closure => function (string $attribut, mixed $valeur, \Closure $echec): void {
                            if (preg_match('/^(\d{4})-(\d{4})$/', (string) $valeur, $m) === 1 && (int) $m[2] !== (int) $m[1] + 1) {
                                $echec('La seconde année doit suivre la première, par exemple 2026-2027.');
                            }
                        })
                        ->disabled(fn (?BaremeScolarite $record): bool => $record?->inscriptions()->exists() ?? false)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Forms\Get $get): Unique => $rule->where('niveau', $get('niveau'))),
                    Forms\Components\Select::make('niveau')->label('Niveau')->required()->options(BaremeScolarite::NIVEAUX)->native(false)
                        ->disabled(fn (?BaremeScolarite $record): bool => $record?->inscriptions()->exists() ?? false),
                    $montant('montant_inscription', 'Inscription (débloque le code d\'accès)'),
                    Forms\Components\TextInput::make('inscription_echeance')->label('Échéance de l\'inscription')->maxLength(120)
                        ->placeholder('Dès réception de la notification (avant la rentrée)'),
                    $montant('montant_tranche1', '1re tranche'),
                    Forms\Components\DatePicker::make('tranche1_echeance')->label('Échéance de la 1re tranche')->native(false)->displayFormat('d/m/Y'),
                    $montant('montant_tranche2', '2e tranche'),
                    Forms\Components\DatePicker::make('tranche2_echeance')->label('Échéance de la 2e tranche')->native(false)->displayFormat('d/m/Y'),
                ]),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('annee_academique', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('annee_academique')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('niveau')->label('Niveau')->formatStateUsing(fn (string $state): string => BaremeScolarite::NIVEAUX[$state] ?? $state),
                Tables\Columns\TextColumn::make('montant_inscription')->label('Inscription')->alignEnd()->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state)),
                Tables\Columns\TextColumn::make('montant_tranche1')->label('1re tranche')->alignEnd()->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state)),
                Tables\Columns\TextColumn::make('montant_tranche2')->label('2e tranche')->alignEnd()->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state)),
                Tables\Columns\TextColumn::make('tranche1_echeance')->label('Échéance 1')->date('d/m/Y')->placeholder('À saisir'),
                Tables\Columns\TextColumn::make('tranche2_echeance')->label('Échéance 2')->date('d/m/Y')->placeholder('À saisir'),
                Tables\Columns\TextColumn::make('inscriptions_count')->label('Inscrits')->counts('inscriptions'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBaremesScolarite::route('/'),
            'create' => Pages\CreateBaremeScolarite::route('/create'),
            'edit' => Pages\EditBaremeScolarite::route('/{record}/edit'),
        ];
    }
}
