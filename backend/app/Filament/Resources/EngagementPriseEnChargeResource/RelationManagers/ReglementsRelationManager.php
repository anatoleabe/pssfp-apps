<?php

declare(strict_types=1);

namespace App\Filament\Resources\EngagementPriseEnChargeResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\EngagementPriseEnChargeResource;
use App\Filament\Resources\EngagementPriseEnChargeResource\EngagementActions;
use App\Models\EngagementPriseEnCharge;
use App\Models\InscriptionAnnuelle;
use App\Models\ReglementOrganisme;
use App\Models\Versement;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\ReglementsOrganismes;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Règlements de l'organisme sur l'engagement (lot A2, §9.10) : chacun réparti en un versement
 * par auditeur. Annulation d'un bloc, par le responsable (ReglementsOrganismes revérifie).
 */
#[BelongsToModule('scolarite')]
class ReglementsRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'reglements';

    protected static ?string $title = 'Règlements';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows()
            && $ownerRecord instanceof EngagementPriseEnCharge
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    /** Sur la page « Voir », l'annulation (responsable) reste disponible. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['saisiPar:id,name', 'annulePar:id,name'])
                ->withCount(['versements as versements_valides_count' => fn (Builder $v) => $v->whereNull('annule_at')]))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date_reglement')->label('Date')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('montant')->label('Montant')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state)),
                Tables\Columns\TextColumn::make('mode')->label('Mode')->formatStateUsing(fn (string $state): string => Versement::libelleMode($state)),
                Tables\Columns\TextColumn::make('reference')->label('Référence')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('versements_valides_count')->label('Versements')->alignEnd(),
                Tables\Columns\TextColumn::make('saisiPar.name')->label('Saisi par')->placeholder('Compte supprimé'),
                Tables\Columns\TextColumn::make('etat')->label('État')->badge()
                    ->getStateUsing(fn (ReglementOrganisme $record): string => $record->estAnnule() ? 'Annulé' : 'Valide')
                    ->color(fn (ReglementOrganisme $record): string => $record->estAnnule() ? 'danger' : 'success')
                    ->tooltip(fn (ReglementOrganisme $record): ?string => $record->estAnnule()
                        ? 'Le '.$record->annule_at?->timezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y').' par '.($record->annulePar?->name ?? 'un compte supprimé').' : '.$record->annule_motif
                        : null),
            ])
            ->headerActions([])
            ->actions([
                Action::make('voirPiece')
                    ->label('Pièce')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (ReglementOrganisme $record): bool => (bool) auth()->user()?->can('view_auditeur') && Versement::cheminJustificatifValide($record->piece_path))
                    ->action(function (ReglementOrganisme $record) {
                        abort_unless(auth()->user()?->can('view_auditeur') && Versement::cheminJustificatifValide($record->piece_path), 403);
                        activity('scolarite')->causedBy(auth()->user())->performedOn($record)->event('reglement_piece_consultee')->log('Pièce du règlement consultée');

                        return EngagementActions::lienSigne((string) $record->piece_path, 'piece-reglement');
                    }),
                Action::make('annuler')
                    ->label('Annuler le règlement')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ReglementOrganisme $record): bool => ReglementsOrganismes::peutAnnuler(auth()->user()) && ! $record->estAnnule())
                    ->modalHeading('Annuler le règlement')
                    ->modalDescription(fn (ReglementOrganisme $record): string => 'Les '.$record->versements_valides_count.' versement(s) de ce règlement ('
                        .LibelleSituation::fcfa($record->montant).') sont annulés ensemble, avec leurs imputations ; leurs reçus reçoivent une écriture d\'annulation. '
                        .'Un auditeur dont les droits ne sont plus soldés perd son accès : rattachez-le de nouveau si l\'engagement tient toujours.')
                    ->modalSubmitActionLabel('Annuler le règlement')
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\Textarea::make('motif')->label('Motif de l\'annulation')->required()
                            ->minLength(ReglementsOrganismes::MOTIF_MIN)->maxLength(ReglementsOrganismes::MOTIF_MAX)->rows(3),
                    ], ['min' => 'Le motif compte au moins '.ReglementsOrganismes::MOTIF_MIN.' caractères.']))
                    ->action(function (ReglementOrganisme $record, array $data, Action $action): void {
                        try {
                            app(ReglementsOrganismes::class)->annuler($record, $data['motif'] ?? null, auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Annulation impossible')->body($e->getMessage())->persistent()->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->success()->title('Règlement annulé')->send();
                        $this->redirect(EngagementPriseEnChargeResource::getUrl('view', ['record' => $this->getOwnerRecord()]));
                    }),
            ])
            ->bulkActions([]);
    }
}
