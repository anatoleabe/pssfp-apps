<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\ConfirmationActions;
use App\Filament\Resources\VersementAConfirmerResource\Pages;
use App\Models\User;
use App\Models\Versement;
use App\Services\Scolarite\ConfirmationsVersements;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\RelancesConfirmations;
use App\Support\BelongsToModule;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Versements à confirmer (décision du 15/11/2026) : ce que la scolarité vérifie sur présentation du reçu
 * bancaire original. Tant qu'un versement n'est pas confirmé, il ne compte ni pour l'accès ni pour la
 * situation « à jour » de l'auditeur. Lecture et confirmation : `scolarite.versement_confirmer`.
 */
#[BelongsToModule('scolarite')]
class VersementAConfirmerResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Versement::class;

    protected static ?string $modelLabel = 'Versement à confirmer';

    protected static ?string $pluralModelLabel = 'Versements à confirmer';

    protected static ?string $navigationLabel = 'Versements à confirmer';

    protected static ?string $breadcrumb = 'Versements à confirmer';

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationGroup = GroupeNavigation::SCOLARITE;

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'versements-a-confirmer';

    public static function canViewAny(): bool
    {
        return ConfirmationsVersements::peutConfirmer(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return Versement::query()->whereNull('annule_at')->whereDoesntHave('confirmation');
    }

    /** Versements qui attendent leur confirmation ; ceux que l'agent a saisis comptent aussi (un collègue les confirme). */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = static::getEloquentQuery()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Versements à confirmer sur le reçu bancaire';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        $seuil = fn () => RelancesConfirmations::seuil();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['auditeur.candidature:id,nom,prenom,matricule', 'saisiPar:id,name', 'inscription:id,annee_academique', 'recus.annulation', 'confirmation']))
            ->defaultSort('created_at')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Enregistré le')->sortable()
                    ->formatStateUsing(fn ($state): string => $state->setTimezone('Africa/Douala')->format('d/m/Y H:i'))
                    ->description(fn (Versement $v): ?string => $v->created_at->lessThan($seuil()) ? 'Plus de 7 jours : auditeur relancé' : null)
                    ->color(fn (Versement $v): ?string => $v->created_at->lessThan($seuil()) ? 'danger' : null),
                Tables\Columns\TextColumn::make('auditeur')->label('Auditeur')->wrap()
                    ->getStateUsing(fn (Versement $v): string => trim(mb_strtoupper((string) $v->auditeur?->candidature?->nom).' '.$v->auditeur?->candidature?->prenom))
                    ->description(fn (Versement $v): ?string => $v->auditeur?->candidature?->matricule)
                    ->searchable(query: fn (Builder $q, string $search): Builder => $q->whereHas('auditeur.candidature', fn (Builder $c) => $c
                        ->where(fn (Builder $w) => $w->where('nom', 'ilike', '%'.$search.'%')->orWhere('prenom', 'ilike', '%'.$search.'%')->orWhere('matricule', 'ilike', '%'.$search.'%')))),
                Tables\Columns\TextColumn::make('montant')->label('Montant')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state)),
                Tables\Columns\TextColumn::make('date_versement')->label('Versé le')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('mode')->label('Mode')->formatStateUsing(fn (string $state): string => Versement::libelleMode($state)),
                Tables\Columns\TextColumn::make('reference')->label('Référence')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('recu')->label('Reçu')->fontFamily('mono')
                    ->getStateUsing(fn (Versement $v): string => $v->recuEnVigueur()?->numero ?? '—'),
                Tables\Columns\TextColumn::make('saisiPar.name')->label('Saisi par')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('anciens')->label('Ancienneté')
                    ->trueLabel('Plus de 7 jours')->falseLabel('7 jours ou moins')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->where('created_at', '<', RelancesConfirmations::seuil()),
                        false: fn (Builder $q): Builder => $q->where('created_at', '>=', RelancesConfirmations::seuil()),
                    ),
                Tables\Filters\SelectFilter::make('mode')->label('Mode')->options(Versement::MODES),
                Tables\Filters\SelectFilter::make('saisi_par')->label('Saisi par')
                    ->options(fn (): array => User::query()->whereIn('id', static::getEloquentQuery()->select('saisi_par'))->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\TernaryFilter::make('reglement')->label('Origine')
                    ->trueLabel('Règlement d\'un organisme')->falseLabel('Versement de l\'auditeur')
                    ->queries(true: fn (Builder $q): Builder => $q->whereNotNull('reglement_organisme_id'), false: fn (Builder $q): Builder => $q->whereNull('reglement_organisme_id')),
            ])
            ->actions([
                ConfirmationActions::confirmer('confirmer'),
                Tables\Actions\Action::make('fiche')->label('Fiche')->icon('heroicon-o-user')->color('gray')
                    ->url(fn (Versement $v): ?string => $v->auditeur === null ? null : AuditeurResource::getUrl('view', ['record' => $v->auditeur])),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Aucun versement à confirmer')
            ->emptyStateDescription('Les versements enregistrés par le service financier apparaissent ici jusqu\'à leur confirmation sur le reçu bancaire original.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVersementsAConfirmer::route('/')];
    }
}
