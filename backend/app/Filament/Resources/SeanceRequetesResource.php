<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\SeanceRequetesResource\Pages;
use App\Models\Jury;
use App\Models\SeanceRequetes;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Séances du jury des requêtes (D27, D28) : PV numéroté, clos, validé par le chef de la commission. */
#[BelongsToModule('lmd')]
class SeanceRequetesResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = SeanceRequetes::class;

    protected static ?string $modelLabel = 'Séance du jury des requêtes';

    protected static ?string $pluralModelLabel = 'Jury des requêtes';

    protected static ?string $navigationLabel = 'Jury des requêtes';

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'seances-requetes';

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()->columns(2)
                ->description('Le jury des requêtes est désigné par note de service, comme les jurys de délibération (nature « Jury des requêtes »).')
                ->schema([
                    Forms\Components\Select::make('jury_id')->label('Jury')->required()->native(false)
                        ->options(fn (): array => Jury::query()->where('nature', 'requetes')->orderByDesc('acte_date')->get()
                            ->mapWithKeys(fn (Jury $j): array => [$j->id => $j->libelle()])->all()),
                    Forms\Components\DatePicker::make('tenue_le')->label('Date de la séance')->required()->native(false)->displayFormat('d/m/Y'),
                ]),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('jury')->withCount('requetes'))
            ->defaultSort('tenue_le', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('tenue_le')->label('Séance')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('jury.acte_reference')->label('Jury'),
                Tables\Columns\TextColumn::make('requetes_count')->label('Requêtes')->alignEnd(),
                Tables\Columns\TextColumn::make('numero_pv')->label('PV')->fontFamily('mono')->placeholder('—'),
                Tables\Columns\TextColumn::make('etat')->label('État')->badge()
                    ->getStateUsing(fn (SeanceRequetes $s): string => $s->validee_at ? 'Validée' : ($s->close_at ? 'Close, à valider' : 'Ouverte'))
                    ->color(fn (SeanceRequetes $s): string => $s->validee_at ? 'success' : ($s->close_at ? 'warning' : 'gray')),
            ])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(3)->schema([
                Infolists\Components\TextEntry::make('tenue_le')->label('Séance')->date('d/m/Y'),
                Infolists\Components\TextEntry::make('jury.acte_reference')->label('Jury'),
                Infolists\Components\TextEntry::make('numero_pv')->label('PV')->placeholder('Non clos'),
            ]),
            Infolists\Components\Section::make('Requêtes de la séance')->schema([
                Infolists\Components\ViewEntry::make('requetes')->hiddenLabel()->view('filament.lmd.seance-requetes'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeancesRequetes::route('/'),
            'create' => Pages\CreateSeanceRequetes::route('/create'),
            'view' => Pages\ViewSeanceRequetes::route('/{record}'),
        ];
    }
}
