<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\CorrectionVersementResource\Pages;
use App\Models\CorrectionVersement;
use App\Models\DemandeAutorisation;
use App\Models\User;
use App\Services\Scolarite\CorrectionsVersements;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\LibelleSituation;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pièces de correction des versements (ADR-0015, spec de l'espace Finances §7.8, sous-lot F4b) : chaque annulation ou
 * remplacement, avec ses deux auteurs, sa demande, ses reçus et son avant/après. Lecture seule. Accès :
 * `finances.consulter`. La pastille compte les demandes de correction en attente.
 */
#[BelongsToModule('scolarite')]
class CorrectionVersementResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = CorrectionVersement::class;

    protected static ?string $modelLabel = 'Pièce de correction';

    protected static ?string $pluralModelLabel = 'Corrections des versements';

    protected static ?string $navigationLabel = 'Corrections';

    protected static ?string $breadcrumb = 'Corrections';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = GroupeNavigation::FINANCES;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'finances/corrections';

    public static function canViewAny(): bool
    {
        return EspaceFinances::peutConsulter(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<DemandeAutorisation> */
    public static function demandesEnCours(): Builder
    {
        return DemandeAutorisation::query()->where('type', DemandeAutorisation::TYPE_CORRIGER_VERSEMENT)->whereIn('statut', DemandeAutorisation::EN_ATTENTE);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = static::demandesEnCours()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Demandes de correction en attente de décision';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        $versement = fn ($v): string => $v === null ? '—' : $v->reference.' — '.LibelleSituation::fcfa((int) $v->montant);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'versementAnnule.auditeur.candidature:id,matricule', 'versementRemplacant.auditeur.candidature:id,matricule',
                'annulationRecu:id,numero', 'recuRemplacant:id,numero', 'demande:id,uuid,numero', 'demandeePar:id,name', 'executeePar:id,name',
            ]))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Pièce')->fontFamily('mono')->searchable()
                    ->description(fn (CorrectionVersement $c): string => $c->created_at->setTimezone('Africa/Douala')->format('d/m/Y H:i')),
                Tables\Columns\TextColumn::make('nature')->label('Nature')->badge()
                    ->formatStateUsing(fn (string $state): string => CorrectionVersement::NATURES[$state] ?? $state)
                    ->description(fn (CorrectionVersement $c): string => CorrectionVersement::CATEGORIES[$c->categorie] ?? $c->categorie)
                    ->color(fn (string $state): string => $state === CorrectionVersement::ANNULATION ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('auditeur')->label('Matricule')
                    ->getStateUsing(fn (CorrectionVersement $c): string => (string) ($c->versementAnnule?->auditeur?->candidature?->matricule ?? '—'))
                    ->description(fn (CorrectionVersement $c): ?string => $c->versementRemplacant !== null
                        && $c->versementRemplacant->auditeur_id !== $c->versementAnnule?->auditeur_id
                        ? '→ '.$c->versementRemplacant->auditeur?->candidature?->matricule : null),
                Tables\Columns\TextColumn::make('annule')->label('Versement annulé')
                    ->getStateUsing(fn (CorrectionVersement $c): string => $versement($c->versementAnnule))
                    ->description(fn (CorrectionVersement $c): ?string => $c->annulationRecu?->numero),
                Tables\Columns\TextColumn::make('remplacant')->label('Remplaçant')
                    ->getStateUsing(fn (CorrectionVersement $c): string => $versement($c->versementRemplacant))
                    ->description(fn (CorrectionVersement $c): ?string => $c->recuRemplacant?->numero),
                Tables\Columns\TextColumn::make('champs')->label('Changements')->wrap()
                    ->getStateUsing(fn (CorrectionVersement $c): string => implode(' ; ', array_slice(CorrectionsVersements::precisions(
                        ['nature' => $c->nature, 'categorie' => $c->categorie, 'champs' => $c->champs ?? []]), 2)) ?: '—'),
                Tables\Columns\TextColumn::make('auteurs')->label('Demandée par / décidée par')
                    ->getStateUsing(fn (CorrectionVersement $c): string => (string) $c->demandeePar?->name)
                    ->description(fn (CorrectionVersement $c): string => ($c->executeePar?->name ?? '—').($c->demande !== null ? ' — '.$c->demande->numero : ' — sans demande')),
                Tables\Columns\TextColumn::make('confirmation_reportee')->label('Confirmation')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        CorrectionVersement::REPORT_OFFICE => 'Reportée d\'office',
                        CorrectionVersement::REPORT_ATTESTE => 'Attestée par le décideur',
                        default => '—',
                    })->placeholder('—'),
                Tables\Columns\TextColumn::make('motif')->label('Motif')->wrap()->limit(80)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('nature')->label('Nature')->options(CorrectionVersement::NATURES),
                Tables\Filters\SelectFilter::make('categorie')->label('Catégorie')->options(CorrectionVersement::CATEGORIES),
                Tables\Filters\SelectFilter::make('demandee_par')->label('Demandée par')
                    ->options(fn (): array => User::query()->whereIn('id', CorrectionVersement::query()->select('demandee_par'))->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('executee_par')->label('Décidée par')
                    ->options(fn (): array => User::query()->whereIn('id', CorrectionVersement::query()->select('executee_par'))->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\Filter::make('periode')->label('Période')
                    ->form([
                        Forms\Components\DatePicker::make('du')->label('Du')->native(false)->displayFormat('d/m/Y'),
                        Forms\Components\DatePicker::make('au')->label('Au')->native(false)->displayFormat('d/m/Y'),
                    ])
                    // Jours de Yaoundé, bornes converties au fuseau de l'application (UTC en production).
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['du'] ?? null), fn (Builder $b) => $b->where('created_at', '>=', EspaceFinances::debutJour((string) $data['du'])))
                        ->when(filled($data['au'] ?? null), fn (Builder $b) => $b->where('created_at', '<', EspaceFinances::finJour((string) $data['au'])))),
            ])
            ->actions([
                Tables\Actions\Action::make('demande')->label('Demande')->icon('heroicon-o-document-text')->color('gray')
                    ->visible(fn (CorrectionVersement $c): bool => $c->demande !== null)
                    ->url(fn (CorrectionVersement $c): ?string => $c->demande === null ? null : DemandeAutorisationResource::getUrl('view', ['record' => $c->demande])),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Aucune correction')
            ->emptyStateDescription('Les annulations et les remplacements de versements apparaissent ici, avec leur pièce numérotée.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCorrectionsVersements::route('/')];
    }
}
