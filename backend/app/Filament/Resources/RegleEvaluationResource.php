<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\RegleEvaluationResource\Pages;
use App\Models\RegleEvaluation;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Règles d'évaluation d'une année (D1, D2, D10, D14 à D16, V-02 à V-09).
 * Toute modification est journalisée (ancienne et nouvelle valeur).
 */
#[BelongsToModule('lmd')]
class RegleEvaluationResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = RegleEvaluation::class;

    protected static ?string $modelLabel = 'Règles d\'évaluation';

    protected static ?string $pluralModelLabel = 'Règles d\'évaluation';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        $note = fn (string $champ, string $libelle): Forms\Components\TextInput => Forms\Components\TextInput::make($champ)
            ->label($libelle)->numeric()->minValue(0)->maxValue(20)->step(0.01)->suffix('/ 20');
        $pourcent = fn (string $champ, string $libelle): Forms\Components\TextInput => Forms\Components\TextInput::make($champ)
            ->label($libelle)->required()->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
            ->formatStateUsing(fn ($state): ?string => $state === null ? null : rtrim(rtrim(number_format((float) $state * 100, 2, '.', ''), '0'), '.'))
            ->dehydrateStateUsing(fn ($state): string => number_format((float) $state / 100, 4, '.', ''));

        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make('Année')->schema([
                Forms\Components\TextInput::make('annee_academique')->label('Année académique')->required()
                    ->regex('/^(\d{4})-(\d{4})$/')->placeholder('2026-2027')->maxLength(9)
                    ->unique(ignoreRecord: true)->disabledOn('edit'),
            ]),
            Forms\Components\Section::make('Validation d\'une UE')->columns(2)
                ->description('Pas de compensation entre UE : chaque UE se valide seule et reste acquise (capitalisation).')
                ->schema([
                    $note('seuil_validation', 'Seuil de validation')->required(),
                    Forms\Components\Select::make('note_ue')->label('Note de l\'UE')->required()->native(false)
                        ->options(RegleEvaluation::CALCULS_NOTE_UE),
                    $note('note_plancher', 'Note éliminatoire (vide : aucune)')
                        ->helperText('Sous cette note d\'examen, l\'UE n\'est pas validable, quelle que soit sa moyenne.'),
                    Forms\Components\Select::make('note_plancher_porte')->label('La note éliminatoire porte sur')->required()->native(false)
                        ->options(RegleEvaluation::PORTEES_PLANCHER),
                    Forms\Components\Select::make('mode_rattrapage')->label('Rattrapage')->required()->native(false)
                        ->options(RegleEvaluation::MODES_RATTRAPAGE)->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Pondération par défaut d\'une UE')->columns(3)
                ->description('Appliquée aux UE à leur entrée dans une maquette ; chaque UE peut avoir la sienne. Au PSSFP, la note de TP est la note d\'assiduité.')
                ->schema([
                    $pourcent('pcc_defaut', 'Contrôle continu'),
                    $pourcent('ptp_defaut', 'Assiduité (TP)'),
                    $pourcent('pexam_defaut', 'Examen'),
                ]),
            Forms\Components\Section::make('Décision annuelle')->columns(2)
                ->description('M1 : tous les crédits du niveau = admis (AD), sinon ajourné (AJ). M2 : les crédits écrits = autorisé à soutenir (AS), tous les crédits avec le mémoire = AD.')
                ->schema([
                    Forms\Components\TextInput::make('credits_niveau')->label('Crédits d\'un niveau')->required()->integer()->minValue(1)->maxValue(120),
                    Forms\Components\TextInput::make('credits_ecrits_terminal')->label('Crédits écrits du M2 (AS)')->required()->integer()->minValue(0)->maxValue(120)
                        ->lte('credits_niveau'),
                ]),
            Forms\Components\Section::make('Grades et points')->collapsible()->collapsed()
                ->description('Grille nationale (D1). Chaque tranche inclut sa borne basse et exclut sa borne haute.')
                ->schema([
                    Forms\Components\Repeater::make('grille_grades')->hiddenLabel()->columns(5)->reorderable(false)->defaultItems(0)
                        ->schema([
                            Forms\Components\TextInput::make('min')->label('De')->required()->numeric()->minValue(0)->maxValue(20),
                            Forms\Components\TextInput::make('max')->label('À (exclu)')->required()->numeric()->minValue(0)->maxValue(20.01),
                            Forms\Components\TextInput::make('grade')->label('Grade')->required()->maxLength(3),
                            Forms\Components\TextInput::make('appreciation')->label('Appréciation')->required()->maxLength(40),
                            Forms\Components\TextInput::make('points')->label('Points')->required()->numeric()->minValue(0)->maxValue(4),
                        ]),
                ]),
            Forms\Components\Section::make('Mentions du diplôme')->collapsible()->collapsed()
                ->description('Par la moyenne du cycle sur 20 (D15). Les félicitations sont proposées par le système et confirmées par le jury.')
                ->schema([
                    Forms\Components\Repeater::make('grille_mentions')->hiddenLabel()->columns(2)->reorderable(false)->defaultItems(0)
                        ->schema([
                            Forms\Components\TextInput::make('min')->label('À partir de')->required()->numeric()->minValue(0)->maxValue(20),
                            Forms\Components\TextInput::make('mention')->label('Mention')->required()->maxLength(80),
                        ]),
                ]),
            Forms\Components\Section::make('Jury et publication')->columns(3)->collapsible()
                ->description('Valeurs proposées par le registre des décisions (V-02 à V-09), à valider par la direction.')
                ->schema([
                    Forms\Components\Select::make('jury.quorum_mode')->label('Quorum')->required()->native(false)
                        ->options(['pourcentage' => 'Pourcentage des membres, président présent', 'tous_designes' => 'Tous les membres désignés']),
                    Forms\Components\TextInput::make('jury.quorum_pct')->label('Membres présents au moins')->required()->integer()->minValue(1)->maxValue(100)->suffix('%'),
                    Forms\Components\Select::make('jury.signataires')->label('Signatures du PV')->required()->native(false)
                        ->options(['tous_presents' => 'Tous les membres présents', 'president_rapporteur' => 'Président et rapporteur']),
                    Forms\Components\TextInput::make('jury.relevement_ecart_max')->label('Relèvement maximal')->required()->numeric()->minValue(0)->maxValue(5)->suffix('point'),
                    Forms\Components\TextInput::make('jury.repechage_ecart_max')->label('Repêchage : écart maximal au seuil')->required()->numeric()->minValue(0)->maxValue(5)->suffix('point'),
                    Forms\Components\Toggle::make('jury.note_eliminatoire_opposable')->label('La note éliminatoire s\'impose au jury')->inline(false),
                    Forms\Components\Select::make('jury.rectification_defavorable')->label('Rectification défavorable')->required()->native(false)
                        ->options(['dans_delai' => 'Dans le délai', 'interdite' => 'Interdite', 'autorisee' => 'Autorisée']),
                    Forms\Components\TextInput::make('jury.rectification_delai_jours')->label('Délai de rectification')->required()->integer()->minValue(0)->maxValue(365)->suffix('jours'),
                    Forms\Components\TextInput::make('jury.delai_requetes_jours')->label('Délai des requêtes après publication')->required()->integer()->minValue(0)->maxValue(60)->suffix('jours'),
                    Forms\Components\TextInput::make('jury.delai_publication_max_jours')->label('Publication au plus tard (M2)')->required()->integer()->minValue(0)->maxValue(120)->suffix('jours après les examens'),
                ]),
        ], [
            'unique' => 'Des règles existent déjà pour cette année.',
            'regex' => 'Saisissez l\'année au format 2026-2027.',
            'lte' => 'Cette valeur ne peut pas dépasser les crédits d\'un niveau.',
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('annee_academique', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('annee_academique')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('seuil_validation')->label('Seuil')->suffix(' / 20'),
                Tables\Columns\TextColumn::make('note_plancher')->label('Note éliminatoire')->suffix(' / 20')->placeholder('aucune'),
                Tables\Columns\TextColumn::make('mode_rattrapage')->label('Rattrapage')->wrap()
                    ->formatStateUsing(fn (string $state): string => RegleEvaluation::MODES_RATTRAPAGE[$state] ?? $state),
                Tables\Columns\TextColumn::make('ponderation')->label('CC / assiduité / examen')
                    ->getStateUsing(fn (RegleEvaluation $r): string => sprintf('%s / %s / %s %%', round((float) $r->pcc_defaut * 100), round((float) $r->ptp_defaut * 100), round((float) $r->pexam_defaut * 100))),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReglesEvaluation::route('/'),
            'create' => Pages\CreateRegleEvaluation::route('/create'),
            'edit' => Pages\EditRegleEvaluation::route('/{record}/edit'),
        ];
    }
}
