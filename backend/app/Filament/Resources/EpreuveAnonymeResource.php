<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\EpreuveAnonymeResource\Pages;
use App\Models\EpreuveAnonyme;
use App\Models\EtapeSaisie;
use App\Models\MaquetteEcue;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Épreuves anonymes (spec module 4, lot 9 ; D36). */
#[BelongsToModule('lmd')]
class EpreuveAnonymeResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = EpreuveAnonyme::class;

    protected static ?string $modelLabel = 'Épreuve anonyme';

    protected static ?string $pluralModelLabel = 'Anonymat des copies';

    protected static ?string $navigationLabel = 'Anonymat des copies';

    protected static ?string $navigationIcon = 'heroicon-o-eye-slash';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 45;

    protected static ?string $slug = 'epreuves-anonymes';

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()->columns(2)
                ->description('Une épreuve par ECUE et par session. L\'examen ne se saisit plus en clair : il se saisit sur les numéros.')
                ->schema([
                    Forms\Components\Select::make('maquette_ecue_id')->label('ECUE')->required()->searchable()
                        ->options(fn (): array => MaquetteEcue::query()->whereHas('maquetteUe.maquette', fn ($q) => $q->where('statut', 'validee'))
                            ->with('maquetteUe.maquette.parcours')->orderBy('code')->get()
                            ->mapWithKeys(fn (MaquetteEcue $e): array => [$e->id => $e->libelle().' ('.$e->maquetteUe->maquette->libelle().')'])->all()),
                    Forms\Components\Select::make('session')->label('Session')->required()->native(false)->options(EtapeSaisie::SESSIONS)->default('normale'),
                ]),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('ecue')->withCount('copies'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('ecue.code')->label('ECUE')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('ecue.intitule')->label('Intitulé')->wrap(),
                Tables\Columns\TextColumn::make('session')->label('Session')->formatStateUsing(fn (string $state): string => EtapeSaisie::SESSIONS[$state] ?? $state),
                Tables\Columns\TextColumn::make('copies_count')->label('Copies')->alignEnd(),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => EpreuveAnonyme::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'levee' => 'success', 'verrouillee' => 'info', 'anonymisee' => 'warning', default => 'gray'
                    }),
            ])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('libelle')->label('Épreuve')->getStateUsing(fn (EpreuveAnonyme $e): string => $e->libelle()),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => EpreuveAnonyme::STATUTS[$state] ?? $state),
                Infolists\Components\TextEntry::make('autorisee_at')->label('Levée autorisée')->dateTime('d/m/Y H:i', 'Africa/Douala')->placeholder('—'),
                Infolists\Components\TextEntry::make('pv_sha256')->label('Sceau du PV de levée')->fontFamily('mono')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Copies')->schema([
                Infolists\Components\ViewEntry::make('copies')->hiddenLabel()->view('filament.lmd.copies-anonymes'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEpreuvesAnonymes::route('/'),
            'create' => Pages\CreateEpreuveAnonyme::route('/create'),
            'view' => Pages\ViewEpreuveAnonyme::route('/{record}'),
        ];
    }
}
