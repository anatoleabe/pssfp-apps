<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\SeanceResource\Pages;
use App\Models\AttributionEcue;
use App\Models\MaquetteEcue;
use App\Models\Seance;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Séances et pointage (spec module 4, lot 10 ; D12, D13). */
#[BelongsToModule('lmd')]
class SeanceResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Seance::class;

    protected static ?string $modelLabel = 'Séance';

    protected static ?string $pluralModelLabel = 'Séances et assiduité';

    protected static ?string $navigationLabel = 'Séances et assiduité';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = GroupeNavigation::PLANIFICATION;

    protected static ?int $navigationSort = 50;

    /** ECUE visibles : tous pour la scolarité, les siens pour un enseignant. */
    public static function ecuesAccessibles(): Builder
    {
        $query = MaquetteEcue::query()->whereHas('maquetteUe.maquette', fn ($q) => $q->where('statut', 'validee'));
        if (! auth()->user()?->can(PermissionsLmd::ASSIDUITE)) {
            $query->whereIn('id', AttributionEcue::query()->where('user_id', auth()->id())->select('maquette_ecue_id'));
        }

        return $query;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('maquette_ecue_id', self::ecuesAccessibles()->select('id'));
    }

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\Select::make('maquette_ecue_id')->label('ECUE')->required()->searchable()->columnSpanFull()
                    ->options(fn (): array => self::ecuesAccessibles()->with('maquetteUe.maquette.parcours')->orderBy('code')->get()
                        ->mapWithKeys(fn (MaquetteEcue $e): array => [$e->id => $e->libelle().' ('.$e->maquetteUe->maquette->libelle().')'])->all()),
                Forms\Components\DatePicker::make('date_seance')->label('Date')->required()->native(false)->displayFormat('d/m/Y'),
                Forms\Components\TimePicker::make('debut')->label('Début')->required()->seconds(false),
                Forms\Components\TimePicker::make('fin')->label('Fin')->required()->seconds(false)->after('debut'),
                Forms\Components\Select::make('modalite')->label('Modalité')->required()->native(false)->options(Seance::MODALITES)->default('hybride'),
                Forms\Components\TextInput::make('salle')->label('Salle')->maxLength(120),
                Forms\Components\TextInput::make('lien_reunion')->label('Lien de la réunion')->url()->maxLength(500),
            ]),
        ], ['after' => 'L\'heure de fin doit suivre l\'heure de début.', 'url' => 'Saisissez une adresse valide (https://…).']));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('ecue')->withCount([
                'pointages as presents_count' => fn ($q) => $q->where('statut', 'present'),
                'pointages',
            ]))
            ->defaultSort('date_seance', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date_seance')->label('Date')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('horaire')->label('Horaire')->getStateUsing(fn (Seance $s): string => substr((string) $s->debut, 0, 5).' – '.substr((string) $s->fin, 0, 5)),
                Tables\Columns\TextColumn::make('ecue.code')->label('ECUE')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('modalite')->label('Modalité')->formatStateUsing(fn (string $state): string => Seance::MODALITES[$state] ?? $state),
                Tables\Columns\TextColumn::make('presence')->label('Présents')->getStateUsing(fn (Seance $s): string => $s->pointages_count > 0 ? $s->presents_count.' / '.$s->pointages_count : '—'),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => Seance::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'tenue' => 'success', 'annulee', 'reportee' => 'gray', default => 'warning'
                    }),
            ])
            ->filters([Tables\Filters\SelectFilter::make('statut')->label('État')->options(Seance::STATUTS)])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('libelle')->label('Séance')->getStateUsing(fn (Seance $s): string => $s->libelle()),
                Infolists\Components\TextEntry::make('modalite')->label('Modalité')->formatStateUsing(fn (string $state): string => Seance::MODALITES[$state] ?? $state),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => Seance::STATUTS[$state] ?? $state),
                Infolists\Components\TextEntry::make('motif_annulation')->label('Motif d\'annulation')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Feuille de présence')->schema([
                Infolists\Components\ViewEntry::make('pointages')->hiddenLabel()->view('filament.lmd.feuille-presence'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeances::route('/'),
            'create' => Pages\CreateSeance::route('/create'),
            'view' => Pages\ViewSeance::route('/{record}'),
        ];
    }
}
