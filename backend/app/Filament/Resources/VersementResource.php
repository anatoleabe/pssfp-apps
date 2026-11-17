<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Filament\Resources\AuditeurResource\CorrectionActions;
use App\Filament\Resources\AuditeurResource\RecuActions;
use App\Filament\Resources\VersementResource\Pages;
use App\Models\InscriptionAnnuelle;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\RelancesConfirmations;
use App\Services\Scolarite\VersementsExcel;
use App\Support\BelongsToModule;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Liste de tous les versements (espace Finances, spec module-4-scolarite-espace-financier §6.2) : statut déduit des
 * tables (enregistré, confirmé, annulé), filtres, recherche, somme des lignes filtrées, export. Lecture seule : un
 * versement ne se modifie jamais. Accès : `finances.consulter`.
 */
#[BelongsToModule('scolarite')]
class VersementResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Versement::class;

    protected static ?string $modelLabel = 'Versement';

    protected static ?string $pluralModelLabel = 'Versements';

    protected static ?string $navigationLabel = 'Versements';

    protected static ?string $breadcrumb = 'Versements';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = GroupeNavigation::FINANCES;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'finances/versements';

    /** Statuts montrés à l'ouverture : l'argent qui compte. */
    public const STATUTS_PAR_DEFAUT = [Versement::STATUT_ENREGISTRE, Versement::STATUT_CONFIRME];

    public static function canViewAny(): bool
    {
        return EspaceFinances::peutConsulter(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'auditeur.candidature:id,nom,prenom,matricule', 'inscription:id,annee_academique,niveau', 'saisiPar:id,name',
                'recus.annulation', 'confirmation.confirmePar:id,name', 'correction.versementRemplacant:id,reference', 'correctionOrigine.versementAnnule:id,reference',
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date_versement')->label('Versé le')->date('d/m/Y')->sortable()
                    ->description(fn (Versement $v): string => 'Enregistré le '.$v->created_at->setTimezone('Africa/Douala')->format('d/m/Y H:i')),
                Tables\Columns\TextColumn::make('auditeur')->label('Auditeur')->wrap()
                    ->getStateUsing(fn (Versement $v): string => trim(mb_strtoupper((string) $v->auditeur?->candidature?->nom).' '.$v->auditeur?->candidature?->prenom))
                    ->description(fn (Versement $v): ?string => $v->auditeur?->candidature?->matricule)
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::rechercher($query, $search)),
                Tables\Columns\TextColumn::make('inscription.annee_academique')->label('Année')
                    ->description(fn (Versement $v): ?string => $v->inscription?->niveau),
                Tables\Columns\TextColumn::make('montant')->label('Montant')->alignEnd()->sortable()
                    ->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state))
                    ->summarize(Sum::make()->label('Total des lignes')->formatStateUsing(fn ($state): string => LibelleSituation::fcfa((int) $state))),
                Tables\Columns\TextColumn::make('mode')->label('Mode')->formatStateUsing(fn (string $state): string => Versement::libelleMode($state))
                    ->description(fn (Versement $v): string => VersementsExcel::origine($v)),
                Tables\Columns\TextColumn::make('reference')->label('Référence')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('payeur')->label('Payé par')->formatStateUsing(fn (string $state): string => Versement::PAYEURS[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('saisiPar.name')->label('Saisi par')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('recu')->label('Reçu')->fontFamily('mono')
                    ->getStateUsing(fn (Versement $v): string => $v->recuEnVigueur()?->numero ?? '—'),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge()
                    ->getStateUsing(fn (Versement $v): string => Versement::STATUTS[VersementsExcel::statut($v)])
                    ->color(fn (Versement $v): string => match (VersementsExcel::statut($v)) {
                        Versement::STATUT_CONFIRME => 'success',
                        Versement::STATUT_ANNULE, Versement::STATUT_REMPLACE => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (Versement $v): ?string => match (VersementsExcel::statut($v)) {
                        Versement::STATUT_CONFIRME => 'Le '.$v->confirmation->confirme_at->setTimezone('Africa/Douala')->format('d/m/Y'),
                        Versement::STATUT_ANNULE => 'Le '.$v->annule_at->setTimezone('Africa/Douala')->format('d/m/Y').' : '.$v->annule_motif,
                        Versement::STATUT_REMPLACE => 'Le '.$v->annule_at->setTimezone('Africa/Douala')->format('d/m/Y').', par '.$v->correction->versementRemplacant?->reference.' ('.$v->correction->numero.')',
                        default => 'À confirmer par la scolarité',
                    }.($v->correctionOrigine !== null ? ' — remplace '.$v->correctionOrigine->versementAnnule?->reference.' ('.$v->correctionOrigine->numero.')' : '')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->label('Statut')->multiple()
                    ->options(Versement::STATUTS)->default(self::STATUTS_PAR_DEFAUT)
                    ->query(fn (Builder $query, array $data): Builder => ($data['values'] ?? []) === [] ? $query : Versement::filtrerStatut($query, $data['values'])),
                Tables\Filters\Filter::make('periode')->label('Période')
                    ->form([
                        Forms\Components\Select::make('date')->label('Date retenue')->options(['versement' => 'Date du versement', 'enregistrement' => 'Date d\'enregistrement'])
                            ->default('versement')->selectablePlaceholder(false)->native(false),
                        Forms\Components\DatePicker::make('du')->label('Du')->native(false)->displayFormat('d/m/Y'),
                        Forms\Components\DatePicker::make('au')->label('Au')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::filtrerPeriode($query, $data)),
                Tables\Filters\SelectFilter::make('mode')->label('Mode')->options(Versement::MODES + [Versement::MODE_REPRISE => 'Reprise du pointage']),
                Tables\Filters\SelectFilter::make('payeur')->label('Payé par')->options(Versement::PAYEURS),
                Tables\Filters\SelectFilter::make('origine')->label('Origine')
                    ->options(['saisie' => 'Saisie', 'reglement' => 'Règlement d\'un organisme', 'reprise' => 'Reprise du pointage'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'reglement' => $query->whereNotNull('reglement_organisme_id'),
                        'reprise' => $query->where('mode', Versement::MODE_REPRISE),
                        'saisie' => $query->whereNull('reglement_organisme_id')->where('mode', '<>', Versement::MODE_REPRISE),
                        default => $query,
                    }),
                Tables\Filters\SelectFilter::make('saisi_par')->label('Saisi par')
                    ->options(fn (): array => User::query()->whereIn('id', Versement::query()->select('saisi_par'))->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('annee')->label('Année académique')
                    ->options(fn (): array => Versement::query()->join('inscriptions_annuelles', 'inscriptions_annuelles.id', '=', 'versements.inscription_annuelle_id')
                        ->distinct()->orderByDesc('inscriptions_annuelles.annee_academique')->pluck('inscriptions_annuelles.annee_academique', 'inscriptions_annuelles.annee_academique')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query
                        : $query->whereHas('inscription', fn (Builder $i) => $i->where('annee_academique', $data['value']))),
                Tables\Filters\SelectFilter::make('niveau')->label('Niveau')->options(['M1' => 'M1', 'M2' => 'M2'])
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query
                        : $query->whereHas('inscription', fn (Builder $i) => $i->where('niveau', $data['value']))),
                Tables\Filters\TernaryFilter::make('recu')->label('Reçu')->trueLabel('Avec reçu en vigueur')->falseLabel('Sans reçu en vigueur')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereHas('recus', fn (Builder $r) => $r->whereDoesntHave('annulation')),
                        false: fn (Builder $q): Builder => $q->whereDoesntHave('recus', fn (Builder $r) => $r->whereDoesntHave('annulation')),
                    ),
                Tables\Filters\TernaryFilter::make('bordereau')->label('Bordereau')->trueLabel('Avec bordereau')->falseLabel('Sans bordereau')
                    ->queries(true: fn (Builder $q): Builder => $q->whereNotNull('justificatif_path'), false: fn (Builder $q): Builder => $q->whereNull('justificatif_path')),
                Tables\Filters\Filter::make('non_confirmes_7j')->label('Plus de 7 jours sans confirmation')->toggle()
                    ->query(fn (Builder $query): Builder => Versement::filtrerStatut($query, Versement::STATUT_ENREGISTRE)->where('created_at', '<', RelancesConfirmations::seuil())),
            ])
            ->filtersFormColumns(2)
            ->actions([Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('fiche')->label('Fiche de l\'auditeur')->icon('heroicon-o-user')
                    ->url(fn (Versement $v): ?string => $v->auditeur === null ? null : AuditeurResource::getUrl('view', ['record' => $v->auditeur])),
                RecuActions::imprimer(fn (Versement $v): ?RecuVersement => $v->recuEnVigueur()),
                Tables\Actions\Action::make('recu')->label('Reçu (PDF)')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Versement $v): bool => $v->recuEnVigueur() !== null)
                    ->action(fn (Versement $v): StreamedResponse => RecuActions::reponse($v->recuEnVigueur())),
                AuditeurActions::voirJustificatif(),
                CorrectionActions::rectifier(),
                CorrectionActions::corriger(),
                CorrectionActions::annuler(),
            ])->label('Actions')->icon('heroicon-o-ellipsis-vertical')])
            ->bulkActions([])
            ->emptyStateHeading('Aucun versement')
            ->emptyStateDescription('Aucun versement ne répond aux filtres choisis.');
    }

    /** Recherche par nom, prénom ou matricule de l'auditeur, référence du bordereau ou numéro de reçu. */
    public static function rechercher(Builder $q, string $search): Builder
    {
        $motif = '%'.addcslashes(trim($search), '%_\\').'%';

        return $q->where(fn (Builder $w) => $w->where('reference', 'ilike', $motif)
            ->orWhereHas('auditeur.candidature', fn (Builder $c) => $c->where(fn (Builder $n) => $n->where('nom', 'ilike', $motif)
                ->orWhere('prenom', 'ilike', $motif)->orWhere('matricule', 'ilike', $motif)))
            ->orWhereHas('recus', fn (Builder $r) => $r->where('numero', 'ilike', $motif)));
    }

    /** @param  array<string, mixed>  $data */
    private static function filtrerPeriode(Builder $q, array $data): Builder
    {
        $du = filled($data['du'] ?? null) ? (string) $data['du'] : null;
        $au = filled($data['au'] ?? null) ? (string) $data['au'] : null;
        if (($data['date'] ?? 'versement') === 'enregistrement') {
            // Jours de Yaoundé, bornes converties au fuseau de l'application (UTC en production).
            return $q->when($du !== null, fn (Builder $b) => $b->where('created_at', '>=', EspaceFinances::debutJour($du)))
                ->when($au !== null, fn (Builder $b) => $b->where('created_at', '<', EspaceFinances::finJour($au)));
        }
        $jour = fn (string $d): string => CarbonImmutable::parse($d, InscriptionAnnuelle::FUSEAU)->toDateString();

        return $q->when($du !== null, fn (Builder $b) => $b->where('date_versement', '>=', $jour($du)))
            ->when($au !== null, fn (Builder $b) => $b->where('date_versement', '<=', $jour($au)));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVersements::route('/')];
    }
}
