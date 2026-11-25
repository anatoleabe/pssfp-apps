<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\EngagementPriseEnChargeResource\Pages;
use App\Filament\Resources\EngagementPriseEnChargeResource\RelationManagers;
use App\Models\EngagementPriseEnCharge;
use App\Models\InscriptionAnnuelle;
use App\Services\Scolarite\EngagementsPriseEnCharge;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\SuiviEngagement;
use App\Support\BelongsToModule;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

/**
 * Vie académique > Prises en charge (spec module 4, lot A2 §9.10) : les engagements des
 * organismes, avec ce qu'ils couvrent, ce qui est réglé et ce qui reste. Lecture seule ici :
 * tout geste passe par les actions de la fiche (EngagementActions), qui délèguent aux services
 * EngagementsPriseEnCharge et ReglementsOrganismes, lesquels revérifient les droits.
 */
#[BelongsToModule('scolarite')]
class EngagementPriseEnChargeResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = EngagementPriseEnCharge::class;

    protected static ?string $modelLabel = 'Prise en charge';

    protected static ?string $pluralModelLabel = 'Prises en charge';

    /** Libellé tel quel dans le menu : « Prises en charge », pas « Prises En Charge » (lot 33). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = GroupeNavigation::SCOLARITE;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'scolarite/prises-en-charge';

    protected static ?string $recordTitleAttribute = 'organisme';

    /** @var WeakMap<EngagementPriseEnCharge, SuiviEngagement>|null suivi calculé une fois par ligne et par requête */
    private static ?WeakMap $suivis = null;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->avecSuivi()->with('creePar:id,name');
    }

    /** La recherche globale n'affiche que l'organisme : pas de suivi à charger. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function suivi(EngagementPriseEnCharge $e): SuiviEngagement
    {
        self::$suivis ??= new WeakMap;

        return self::$suivis[$e] ??= SuiviEngagement::de($e);
    }

    /** Oublie le suivi d'un engagement après un geste. */
    public static function oublier(EngagementPriseEnCharge $e): void
    {
        if (self::$suivis !== null) {
            unset(self::$suivis[$e]);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('organisme')->label('Organisme')->searchable()->sortable()->wrap()->weight('medium'),
                Tables\Columns\TextColumn::make('lettre')->label('Lettre')
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => $record->libelleLettre()),
                Tables\Columns\TextColumn::make('annee_academique')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('portee')->label('Portée')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => EngagementPriseEnCharge::PORTEES[$state] ?? $state),
                Tables\Columns\TextColumn::make('auditeurs')->label('Auditeurs')->alignEnd()
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): int => self::suivi($record)->nombre()),
                Tables\Columns\TextColumn::make('couvert')->label('Couvert')->alignEnd()
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => LibelleSituation::fcfa(self::suivi($record)->couvert)),
                Tables\Columns\TextColumn::make('regle')->label('Réglé')->alignEnd()
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => LibelleSituation::fcfa(self::suivi($record)->regle)),
                Tables\Columns\TextColumn::make('reste')->label('Reste')->alignEnd()->weight('bold')
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => LibelleSituation::fcfa(self::suivi($record)->reste)),
                Tables\Columns\TextColumn::make('echeance')->label('Échéance')->date('d/m/Y')->sortable()
                    ->color(fn (EngagementPriseEnCharge $record): ?string => self::suivi($record)->etat() === EngagementPriseEnCharge::ECHU ? 'danger' : null),
                Tables\Columns\TextColumn::make('etat')->label('État')->badge()
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => self::suivi($record)->libelleEtat())
                    ->color(fn (EngagementPriseEnCharge $record): string => self::couleurEtat(self::suivi($record)->etat())),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('annee_academique')->label('Année')
                    ->options(fn (): array => EngagementsPriseEnCharge::annees()),
                Tables\Filters\Filter::make('echeance_depassee')->label('Échéance dépassée')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('echeance', '<', InscriptionAnnuelle::aujourdhui()->toDateString())),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Engagement')->columns(3)->schema([
                Infolists\Components\TextEntry::make('organisme')->label('Organisme')->weight('bold'),
                Infolists\Components\TextEntry::make('lettre')->label('Lettre')
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => $record->libelleLettre()),
                Infolists\Components\TextEntry::make('etat')->label('État')->badge()
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => self::suivi($record)->libelleEtat())
                    ->color(fn (EngagementPriseEnCharge $record): string => self::couleurEtat(self::suivi($record)->etat())),
                Infolists\Components\TextEntry::make('portee')->label('Portée')
                    ->formatStateUsing(fn (string $state): string => EngagementPriseEnCharge::PORTEES[$state] ?? $state),
                Infolists\Components\TextEntry::make('annee_academique')->label('Année académique'),
                Infolists\Components\TextEntry::make('echeance')->label('Échéance')
                    ->formatStateUsing(fn (EngagementPriseEnCharge $record): string => $record->echeance->format('d/m/Y')
                        .($record->prolongations > 0 ? ' (prolongée '.$record->prolongations.' fois)' : ''))
                    ->color(fn (EngagementPriseEnCharge $record): ?string => self::suivi($record)->etat() === EngagementPriseEnCharge::ECHU ? 'danger' : null),
            ]),
            Infolists\Components\Section::make('Suivi')->columns(4)->schema([
                Infolists\Components\TextEntry::make('suivi_auditeurs')->label('Auditeurs couverts')
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): int => self::suivi($record)->nombre()),
                Infolists\Components\TextEntry::make('suivi_couvert')->label('Couvert')
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => LibelleSituation::fcfa(self::suivi($record)->couvert)),
                Infolists\Components\TextEntry::make('suivi_regle')->label('Réglé par l\'organisme')
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => LibelleSituation::fcfa(self::suivi($record)->regle)),
                Infolists\Components\TextEntry::make('suivi_reste')->label('Reste')->weight('bold')
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => LibelleSituation::fcfa(self::suivi($record)->reste)),
            ]),
            Infolists\Components\Section::make('Contact pour le règlement')->columns(3)->schema([
                Infolists\Components\TextEntry::make('contact_nom')->label('Nom')->placeholder('—'),
                Infolists\Components\TextEntry::make('contact_email')->label('E-mail')->placeholder('—')->copyable(),
                Infolists\Components\TextEntry::make('contact_telephone')->label('Téléphone')->placeholder('—'),
                Infolists\Components\TextEntry::make('relances')->label('Relances de l\'organisme')->columnSpan(3)
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => $record->relances === 0 ? 'Aucune'
                        : $record->relances.' relance(s), la dernière le '.$record->derniere_relance_le?->timezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y à H:i')),
                Infolists\Components\TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpan(3),
                Infolists\Components\TextEntry::make('enregistre')->label('Enregistré')->columnSpan(3)
                    ->getStateUsing(fn (EngagementPriseEnCharge $record): string => 'Par '.($record->creePar?->name ?? 'un compte supprimé')
                        .', le '.$record->created_at?->timezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y à H:i')),
            ]),
        ]);
    }

    public static function couleurEtat(string $etat): string
    {
        return match ($etat) {
            EngagementPriseEnCharge::SOLDE => 'success',
            EngagementPriseEnCharge::ECHU => 'danger',
            EngagementPriseEnCharge::EN_COURS => 'warning',
            default => 'gray',
        };
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AuditeursCouvertsRelationManager::class,
            RelationManagers\ReglementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEngagements::route('/'),
            'view' => Pages\ViewEngagement::route('/{record}'),
        ];
    }
}
