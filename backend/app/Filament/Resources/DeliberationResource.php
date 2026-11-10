<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\DeliberationResource\Pages;
use App\Filament\Resources\DeliberationResource\RelationManagers\MesuresRelationManager;
use App\Models\Deliberation;
use App\Models\EtapeSaisie;
use App\Models\Jury;
use App\Models\Parcours;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Délibérations (spec module 4, lot 6) : préalables, scénarios et mesures du jury,
 * séance, arrêt, signatures, publication, rectification, PV.
 */
#[BelongsToModule('lmd')]
class DeliberationResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Deliberation::class;

    protected static ?string $modelLabel = 'Délibération';

    protected static ?string $pluralModelLabel = 'Délibérations';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = GroupeNavigation::EVALUATIONS;

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()->columns(3)
                ->description('Un scénario « application stricte des règles » est créé avec la délibération.')
                ->schema([
                    Forms\Components\TextInput::make('annee_academique')->label('Année académique')->required()->regex('/^(\d{4})-(\d{4})$/')->default('2026-2027')->live(onBlur: true),
                    Forms\Components\Select::make('niveau')->label('Niveau')->required()->native(false)->options(['M1' => 'Master 1', 'M2' => 'Master 2'])->live(),
                    Forms\Components\Select::make('parcours_id')->label('Parcours (vide : tous)')->native(false)
                        ->options(fn (Forms\Get $get): array => Parcours::query()->where('niveau', $get('niveau'))->orderBy('ordre')->get()
                            ->mapWithKeys(fn (Parcours $p): array => [$p->id => $p->libelleComplet()])->all()),
                    Forms\Components\Select::make('session')->label('Session')->required()->native(false)->options(EtapeSaisie::SESSIONS)->default(EtapeSaisie::NORMALE),
                    Forms\Components\Select::make('jury_id')->label('Jury')->native(false)
                        ->options(fn (Forms\Get $get): array => Jury::query()->where('annee_academique', $get('annee_academique'))->where('niveau', $get('niveau'))
                            ->orderByDesc('acte_date')->get()->mapWithKeys(fn (Jury $j): array => [$j->id => $j->libelle()])->all()),
                    Forms\Components\DatePicker::make('date_deliberation')->label('Date prévue')->native(false)->displayFormat('d/m/Y'),
                ]),
        ], ['regex' => 'Saisissez l\'année au format 2026-2027.']));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['parcours', 'jury']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('annee_academique')->label('Année'),
                Tables\Columns\TextColumn::make('niveau')->label('Niveau')->badge(),
                Tables\Columns\TextColumn::make('parcours.code')->label('Parcours')->placeholder('Tous'),
                Tables\Columns\TextColumn::make('session')->label('Session')->formatStateUsing(fn (string $state): string => EtapeSaisie::SESSIONS[$state] ?? $state),
                Tables\Columns\TextColumn::make('numero_pv')->label('PV')->fontFamily('mono')->placeholder('—')
                    ->description(fn (Deliberation $r): ?string => $r->a_rectifier_at !== null && $r->statut === Deliberation::ARRETEE ? 'À rectifier' : null),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (string $state, Deliberation $record): string => (Deliberation::STATUTS[$state] ?? $state).($record->publiee_at ? ' · publiée' : ''))
                    ->color(fn (string $state, Deliberation $record): string => match (true) {
                        $state === Deliberation::RECTIFIEE => 'gray',
                        $record->publiee_at !== null => 'success',
                        $state === Deliberation::ARRETEE => 'info',
                        default => 'warning',
                    }),
            ])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('libelle')->label('Délibération')->getStateUsing(fn (Deliberation $r): string => $r->libelle()),
                Infolists\Components\TextEntry::make('jury.acte_reference')->label('Jury')->placeholder('Non désigné'),
                Infolists\Components\TextEntry::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (string $state, Deliberation $record): string => (Deliberation::STATUTS[$state] ?? $state).($record->publiee_at ? ' · publiée le '.$record->publiee_at->timezone('Africa/Douala')->format('d/m/Y') : '')),
                Infolists\Components\TextEntry::make('numero_pv')->label('PV')->placeholder('—'),
                Infolists\Components\TextEntry::make('seance_ouverte_at')->label('Séance')
                    ->getStateUsing(fn (Deliberation $r): string => $r->seance_ouverte_at === null ? 'Non ouverte'
                        : 'Ouverte le '.$r->seance_ouverte_at->timezone('Africa/Douala')->format('d/m/Y à H:i').' · quorum '.($r->quorum_atteint ? 'atteint' : 'NON atteint')),
                Infolists\Components\TextEntry::make('decision_jury')->label('Décision du jury')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === 'sans_deliberation' ? 'Sans délibération (aucune mesure)' : 'Avec délibération (mesures)'),
                Infolists\Components\TextEntry::make('a_rectifier_motif')->label('À rectifier')->color('danger')->columnSpanFull()
                    ->visible(fn (Deliberation $r): bool => $r->a_rectifier_at !== null && $r->statut === Deliberation::ARRETEE),
                Infolists\Components\TextEntry::make('requetes_jusqu_au')->label('Requêtes jusqu\'au')->date('d/m/Y')->placeholder('—'),
                Infolists\Components\TextEntry::make('motif_rectification')->label('Rectification')->placeholder('—')
                    ->visible(fn (Deliberation $r): bool => $r->rectifie_id !== null)
                    ->getStateUsing(fn (Deliberation $r): string => 'Rectifie '.$r->rectifiee?->numero_pv.' — acte '.$r->acte_rectificatif.' — '.$r->motif_rectification),
            ]),
            Infolists\Components\Section::make('Préalables')->collapsible()
                ->visible(fn (Deliberation $r): bool => $r->estModifiable())
                ->schema([Infolists\Components\ViewEntry::make('prealables')->hiddenLabel()->view('filament.lmd.prealables-deliberation')]),
            Infolists\Components\Section::make('Scénarios')->schema([
                Infolists\Components\ViewEntry::make('scenarios')->hiddenLabel()->view('filament.lmd.scenarios-deliberation'),
            ]),
            Infolists\Components\Section::make('Séance')->collapsible()->collapsed()
                ->visible(fn (Deliberation $r): bool => $r->seance_ouverte_at !== null)
                ->schema([Infolists\Components\ViewEntry::make('seance')->hiddenLabel()->view('filament.lmd.seance-deliberation')]),
            Infolists\Components\Section::make('Résultats')
                ->description(fn (Deliberation $r): string => $r->estModifiable() ? 'Scénario retenu, ou à défaut le dernier calculé : projection, rien n\'est arrêté.' : 'Résultats arrêtés, figés.')
                ->schema([Infolists\Components\ViewEntry::make('resultats')->hiddenLabel()->view('filament.lmd.resultats-deliberation')]),
        ]);
    }

    public static function getRelations(): array
    {
        return [MesuresRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliberations::route('/'),
            'create' => Pages\CreateDeliberation::route('/create'),
            'view' => Pages\ViewDeliberation::route('/{record}'),
        ];
    }
}
