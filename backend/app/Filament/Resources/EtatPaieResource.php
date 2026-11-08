<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\EtatPaieResource\Pages;
use App\Filament\Resources\EtatPaieResource\RelationManagers\LignesRelationManager;
use App\Models\EtatPaie;
use App\Services\Lmd\EtatsPaie;
use App\Support\BelongsToModule;
use Carbon\CarbonImmutable;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** États de paie mensuels des enseignants (lot 13b ; D23, D24) : préparation, circuit jusqu'au virement, PDF. */
#[BelongsToModule('lmd')]
class EtatPaieResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = EtatPaie::class;

    protected static ?string $modelLabel = 'État de paie';

    protected static ?string $pluralModelLabel = 'États de paie';

    protected static ?string $navigationLabel = 'États de paie';

    protected static ?string $breadcrumb = 'États de paie';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Paie des enseignants';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'etats-paie';

    /** @var array<string, array<string, mixed>> */
    private static array $syntheses = [];

    /** Synthèse de l'état, calculée une fois par requête et par version de l'état. @return array<string, mixed> */
    public static function synthese(EtatPaie $e): array
    {
        return self::$syntheses[$e->id.'-'.$e->updated_at?->getTimestamp()] ??= app(EtatsPaie::class)->synthese($e);
    }

    public static function etape(?string $qui, mixed $quand): string
    {
        return $quand === null ? '—' : ($qui ?? '—').', le '.CarbonImmutable::parse($quand)->setTimezone('Africa/Douala')->format('d/m/Y à H:i');
    }

    public static function couleur(string $statut): string
    {
        return match ($statut) {
            EtatPaie::PAYE => 'success', EtatPaie::REJETE => 'danger', EtatPaie::BROUILLON => 'gray', default => 'info',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('periode_du', 'desc')
            ->recordUrl(fn (EtatPaie $e): string => static::getUrl('view', ['record' => $e]))
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->fontFamily('mono')->searchable()
                    ->description(fn (EtatPaie $e): string => $e->objet),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()
                    ->formatStateUsing(fn (string $state): string => EtatPaie::STATUTS[$state] ?? $state)->color(fn (string $state): string => static::couleur($state)),
                Tables\Columns\TextColumn::make('enseignants')->label('Enseignants')->alignEnd()
                    ->getStateUsing(fn (EtatPaie $e): int => $e->lignes()->distinct()->count('enseignant_id')),
                Tables\Columns\TextColumn::make('brut')->label('Brut')->alignEnd()
                    ->getStateUsing(fn (EtatPaie $e): string => EtatsPaie::fcfa((int) $e->lignes()->sum('montant_brut'))),
                Tables\Columns\TextColumn::make('net')->label('Net')->alignEnd()
                    ->getStateUsing(fn (EtatPaie $e): string => EtatsPaie::fcfa((int) $e->lignes()->sum('montant_net'))),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->label('État')->options(EtatPaie::STATUTS),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Ouvrir')]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('État')->columns(3)->schema([
                Infolists\Components\TextEntry::make('numero')->label('Numéro')->fontFamily('mono'),
                Infolists\Components\TextEntry::make('statut')->label('État')->badge()
                    ->formatStateUsing(fn (string $state): string => EtatPaie::STATUTS[$state] ?? $state)->color(fn (string $state): string => static::couleur($state)),
                Infolists\Components\TextEntry::make('periode')->label('Période')
                    ->getStateUsing(fn (EtatPaie $e): string => 'du '.$e->periode_du->format('d/m/Y').' au '.$e->periode_au->format('d/m/Y')),
                Infolists\Components\TextEntry::make('rectifie.numero')->label('Rectifie l\'état')->placeholder('—')->fontFamily('mono'),
                Infolists\Components\TextEntry::make('reference_bordereau')->label('Bordereau')->placeholder('—'),
                Infolists\Components\TextEntry::make('paiement')->label('Virement')
                    ->getStateUsing(fn (EtatPaie $e): ?string => $e->paye_le === null ? null : $e->reference_paiement.' du '.$e->paye_le->format('d/m/Y'))->placeholder('—'),
                Infolists\Components\TextEntry::make('motif_rejet')->label('Motif du rejet')->visible(fn (EtatPaie $e): bool => $e->motif_rejet !== null)->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Circuit')->columns(3)->collapsible()->schema([
                Infolists\Components\TextEntry::make('prepare')->label('Préparé')->getStateUsing(fn (EtatPaie $e): string => static::etape($e->creePar?->name, $e->created_at)),
                Infolists\Components\TextEntry::make('genere')->label('Dernière génération')->getStateUsing(fn (EtatPaie $e): string => static::etape($e->generePar?->name, $e->genere_at)),
                Infolists\Components\TextEntry::make('verifie')->label('Vérifié')->getStateUsing(fn (EtatPaie $e): string => static::etape($e->verifiePar?->name, $e->verifie_at)),
                Infolists\Components\TextEntry::make('vise')->label('Visé')->getStateUsing(fn (EtatPaie $e): string => static::etape($e->visePar?->name, $e->vise_at)),
                Infolists\Components\TextEntry::make('transmis')->label('Transmis')->getStateUsing(fn (EtatPaie $e): string => static::etape($e->transmisPar?->name, $e->transmis_at)),
                Infolists\Components\TextEntry::make('pris')->label('Pris en charge')->getStateUsing(fn (EtatPaie $e): string => static::etape($e->prisEnChargePar?->name, $e->pris_en_charge_at)),
                Infolists\Components\TextEntry::make('rectificatifs')->label('Rectificatifs')->columnSpanFull()
                    ->getStateUsing(fn (EtatPaie $e): string => $e->rectificatifs()->get()->map(fn (EtatPaie $r): string => $r->numero.' ('.mb_strtolower(EtatPaie::STATUTS[$r->statut]).')')->implode(', ') ?: '—'),
            ]),
            Infolists\Components\Section::make('Enseignants écartés')
                ->description('Ils ne figurent pas sur l\'état ; leurs pointages restent disponibles pour un état suivant, une fois la raison levée.')
                ->visible(fn (EtatPaie $e): bool => ($e->ecartes ?? []) !== [])
                ->schema([
                    Infolists\Components\RepeatableEntry::make('ecartes')->label('')->columns(2)->schema([
                        Infolists\Components\TextEntry::make('enseignant')->label('Enseignant'),
                        Infolists\Components\TextEntry::make('raison')->label('Raison'),
                    ]),
                ]),
            Infolists\Components\Section::make('Totaux')->columns(4)->schema([
                Infolists\Components\TextEntry::make('t_enseignants')->label('Enseignants')->getStateUsing(fn (EtatPaie $e): string => (string) static::synthese($e)['totaux']['enseignants']),
                Infolists\Components\TextEntry::make('t_cm')->label('Heures de CM payées')->getStateUsing(fn (EtatPaie $e): string => str_replace('.', ',', (string) static::synthese($e)['totaux']['heures_cm']).' h'),
                Infolists\Components\TextEntry::make('t_service')->label('Heures imputées au service dû')->getStateUsing(fn (EtatPaie $e): string => str_replace('.', ',', (string) static::synthese($e)['totaux']['service']).' h'),
                Infolists\Components\TextEntry::make('t_hors')->label('Heures hors quota (non payées)')->getStateUsing(fn (EtatPaie $e): string => str_replace('.', ',', (string) static::synthese($e)['totaux']['hors_quota']).' h'),
                Infolists\Components\TextEntry::make('t_surv')->label('Surveillances')->getStateUsing(fn (EtatPaie $e): string => (string) static::synthese($e)['totaux']['surveillances']),
                Infolists\Components\TextEntry::make('t_brut')->label('Brut')->getStateUsing(fn (EtatPaie $e): string => EtatsPaie::fcfa((int) static::synthese($e)['totaux']['brut'])),
                Infolists\Components\TextEntry::make('t_retenue')->label('Retenue')->getStateUsing(fn (EtatPaie $e): string => EtatsPaie::fcfa((int) static::synthese($e)['totaux']['retenue'])),
                Infolists\Components\TextEntry::make('t_net')->label('Net à virer')->weight('bold')->getStateUsing(fn (EtatPaie $e): string => EtatsPaie::fcfa((int) static::synthese($e)['totaux']['net'])),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [LignesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEtatsPaie::route('/'),
            'view' => Pages\ViewEtatPaie::route('/{record}'),
        ];
    }
}
