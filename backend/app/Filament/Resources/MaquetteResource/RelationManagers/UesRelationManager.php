<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaquetteResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\Maquette;
use App\Models\MaquetteUe;
use App\Models\RegleEvaluation;
use App\Models\UniteEnseignement;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** UE d'une maquette et leurs ECUE. Modifiables tant que la maquette est en brouillon. */
#[BelongsToModule('lmd')]
class UesRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'ues';

    protected static ?string $title = 'Unités d\'enseignement';

    protected static ?string $modelLabel = 'UE';

    protected static ?string $pluralModelLabel = 'UE';

    protected static ?string $icon = 'heroicon-o-rectangle-stack';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        /** @var Maquette $maquette */
        $maquette = $this->getOwnerRecord();

        return ! $maquette->estModifiable() || ! auth()->user()?->can(PermissionsLmd::REFERENTIEL);
    }

    public function form(Form $form): Form
    {
        $pourcent = fn (string $champ, string $libelle): Forms\Components\TextInput => Forms\Components\TextInput::make($champ)
            ->label($libelle)->required()->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
            ->formatStateUsing(fn ($state): ?string => $state === null ? null : rtrim(rtrim(number_format((float) $state * 100, 2, '.', ''), '0'), '.'))
            ->dehydrateStateUsing(fn ($state): string => number_format((float) $state / 100, 4, '.', ''));
        /** @var Maquette $maquette */
        $maquette = $this->getOwnerRecord();
        $regle = RegleEvaluation::pourAnnee($maquette->annee_academique);

        return $form->columns(4)->schema(MessagesValidation::appliquer([
            Forms\Components\Select::make('unite_enseignement_id')->label('UE')->required()->searchable()->columnSpan(2)
                ->relationship('ue', 'code')
                ->getOptionLabelFromRecordUsing(fn (UniteEnseignement $ue): string => $ue->code.' — '.$ue->intitule)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('maquette_id', $maquette->id))
                ->createOptionForm([
                    Forms\Components\TextInput::make('code')->label('Code')->required()->maxLength(20)->regex('/^[A-Z0-9]+$/')->unique('unites_enseignement', 'code'),
                    Forms\Components\TextInput::make('intitule')->label('Intitulé')->required()->maxLength(255),
                ]),
            Forms\Components\TextInput::make('semestre')->label('Semestre')->required()->integer()->minValue(1)->maxValue(12),
            Forms\Components\TextInput::make('credits')->label('Crédits')->required()->integer()->minValue(0)->maxValue(60),
            $pourcent('pcc', 'Contrôle continu')->default((string) $regle->pcc_defaut),
            $pourcent('ptp', 'Assiduité (TP)')->default((string) $regle->ptp_defaut),
            $pourcent('pexam', 'Examen')->default((string) $regle->pexam_defaut),
            Forms\Components\Select::make('partie')->label('Partie')->required()->native(false)->options(MaquetteUe::PARTIES)->default(MaquetteUe::ECRITE),
            Forms\Components\Repeater::make('ecues')->label('ECUE')->relationship()->columnSpanFull()->columns(8)
                ->orderColumn('ordre')->defaultItems(0)->addActionLabel('Ajouter un ECUE')
                ->schema([
                    Forms\Components\TextInput::make('code')->label('Code')->required()->maxLength(20)->columnSpan(1),
                    Forms\Components\TextInput::make('intitule')->label('Intitulé')->required()->maxLength(500)->columnSpan(2),
                    Forms\Components\TextInput::make('credits')->label('Crédits')->required()->integer()->minValue(0)->maxValue(60),
                    Forms\Components\TextInput::make('heures_cm')->label('CM')->required()->integer()->minValue(0)->default(0),
                    Forms\Components\TextInput::make('heures_td')->label('TD')->required()->integer()->minValue(0)->default(0),
                    Forms\Components\TextInput::make('heures_tp')->label('TP')->required()->integer()->minValue(0)->default(0),
                    Forms\Components\TextInput::make('heures_tpe')->label('TPE')->required()->integer()->minValue(0)->default(0),
                ]),
        ], [
            'unique' => 'Cette UE figure déjà dans la maquette.',
            'regex' => 'Majuscules et chiffres seulement.',
        ]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ue', 'ecues']))
            ->defaultSort('semestre')
            ->columns([
                Tables\Columns\TextColumn::make('semestre')->label('Sem.')->sortable(),
                Tables\Columns\TextColumn::make('ue.code')->label('Code')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('ue.intitule')->label('Intitulé')->wrap(),
                Tables\Columns\TextColumn::make('credits')->label('Crédits')->alignEnd(),
                Tables\Columns\TextColumn::make('ecues_count')->label('ECUE')->alignEnd()
                    ->getStateUsing(fn (MaquetteUe $record): int => $record->ecues->count()),
                Tables\Columns\TextColumn::make('ponderation')->label('CC / assid. / exam.')
                    ->getStateUsing(fn (MaquetteUe $r): string => sprintf('%s / %s / %s %%', round((float) $r->pcc * 100), round((float) $r->ptp * 100), round((float) $r->pexam * 100))),
                Tables\Columns\TextColumn::make('partie')->label('Partie')->badge()
                    ->formatStateUsing(fn (string $state): string => MaquetteUe::PARTIES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === MaquetteUe::SOUTENANCE ? 'info' : 'gray'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Ajouter une UE')->modalWidth('7xl')])
            ->actions([
                Tables\Actions\EditAction::make()->modalWidth('7xl'),
                Tables\Actions\DeleteAction::make()
                    ->before(fn (MaquetteUe $record) => $record->ecues()->delete()),
            ])
            ->paginated(false);
    }
}
