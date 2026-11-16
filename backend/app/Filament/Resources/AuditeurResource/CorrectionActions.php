<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Filament\Resources\DemandeAutorisationResource;
use App\Models\Auditeur;
use App\Models\CorrectionVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Scolarite\CorrectionsVersements;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\RegistreVersements;
use Filament\Forms;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Correction d'un versement (ADR-0015 ; spec module-4-scolarite-espace-financier §7) : la demande du service financier,
 * décidée par un titulaire de l'annulation dans l'écran des demandes ; la correction directe de qui a tous les droits
 * (super administrateur) ; l'annulation directe du responsable, avec sa catégorie (décision Q11). Liste des versements
 * de l'espace Finances et onglet Versements de la fiche.
 */
final class CorrectionActions
{
    private const AUDITEURS_MAX = 20;

    public static function corriger(): TableAction
    {
        return TableAction::make('corriger')
            ->label('Corriger ce versement')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (Versement $record): bool => CorrectionsVersements::peutDemander(auth()->user())
                && CorrectionsVersements::raisonRefus($record) === null && $record->correctionDemandee() === null)
            ->modalHeading('Corriger ce versement')
            ->modalDescription(fn (Versement $record): string => self::direct()
                ? 'Le versement de '.LibelleSituation::fcfa($record->montant).' (référence '.$record->reference.') sera annulé avec son reçu, puis remplacé si vous le demandez. Rien n\'est supprimé : une pièce de correction numérotée relie le tout.'
                : 'Votre demande part au responsable de scolarité ou à un administrateur. À sa décision, le versement de '.LibelleSituation::fcfa($record->montant)
                    .' (référence '.$record->reference.') est annulé avec son reçu, puis remplacé si vous le demandez, et l\'auditeur reçoit le nouveau reçu. Rien n\'est supprimé.')
            ->modalSubmitActionLabel(fn (): string => self::direct() ? 'Corriger' : 'Envoyer la demande')
            ->fillForm(fn (Versement $record): array => ['nature' => CorrectionVersement::REMPLACEMENT] + CorrectionsVersements::valeurs($record))
            ->form(fn (Versement $record): array => MessagesValidation::appliquer([
                Forms\Components\Radio::make('nature')->label('Nature')->options(CorrectionVersement::NATURES)->required()->live(),
                Forms\Components\Select::make('categorie')->label('Catégorie de l\'erreur')->options(CorrectionVersement::CATEGORIES)->required()->native(false),
                Forms\Components\Fieldset::make('Valeurs corrigées')
                    ->visible(fn (Forms\Get $get): bool => $get('nature') === CorrectionVersement::REMPLACEMENT)
                    ->schema([
                        Forms\Components\TextInput::make('montant')->label('Montant (FCFA)')->required()->integer()->minValue(1)->maxValue(RegistreVersements::MONTANT_MAX),
                        Forms\Components\DatePicker::make('date_versement')->label('Date du versement')->required()->native(false)->displayFormat('d/m/Y')
                            ->maxDate(InscriptionAnnuelle::aujourdhui()->endOfDay()->toDateTimeString()),
                        Forms\Components\Select::make('mode')->label('Mode de paiement')->required()->options(Versement::MODES)->native(false),
                        Forms\Components\TextInput::make('reference')->label('Référence du bordereau')->required()->maxLength(100),
                        Forms\Components\Select::make('payeur')->label('Payé par')->required()->options(Versement::PAYEURS)->native(false),
                        Forms\Components\Select::make('auditeur_id')->label('Auditeur')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::auditeurs($search))
                            ->getOptionLabelUsing(fn ($value): string => CorrectionsVersements::afficher('auditeur_id', (int) $value)),
                    ]),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(2000)->rows(3)
                    ->helperText('Ce qui s\'est passé, en une ou deux phrases : il reste dans la pièce de correction.'),
                Forms\Components\Checkbox::make(CorrectionsVersements::OPTION_ATTESTATION)->label(CorrectionsVersements::ATTESTATION)
                    ->visible(fn (): bool => self::direct() && $record->confirmation()->exists()),
                Forms\Components\Checkbox::make(CorrectionsVersements::OPTION_FERMETURE)->label(CorrectionsVersements::FERMETURE)
                    ->visible(fn (): bool => self::direct()),
            ]))
            ->action(function (Versement $record, array $data, TableAction $action): void {
                $saisie = array_intersect_key($data, array_flip(['nature', 'categorie', ...CorrectionsVersements::CHAMPS]));
                $user = auth()->user();
                try {
                    if (self::direct()) {
                        $c = app(CorrectionsVersements::class)->executer($record, CorrectionsVersements::parametres($record, $saisie), (string) $data['motif'], $user, $user,
                            array_intersect_key($data, array_flip([CorrectionsVersements::OPTION_ATTESTATION, CorrectionsVersements::OPTION_FERMETURE])));
                        Notification::make()->success()->title('Correction '.$c->numero.' passée')
                            ->body($c->recuRemplacant !== null ? 'Nouveau reçu : '.$c->recuRemplacant->numero.'.' : null)->send();

                        return;
                    }
                    $d = app(Demandes::class)->soumettre($user, TypesDemande::CORRIGER_VERSEMENT, $record->id, (string) $data['motif'], null, $saisie);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Correction non transmise')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Demande '.$d->numero.' transmise')
                    ->body('Le responsable de scolarité ou un administrateur la décide. Le versement reste en l\'état d\'ici là ; sa confirmation est suspendue.')
                    ->actions([Action::make('voir')->label('Voir la demande')->url(DemandeAutorisationResource::getUrl('view', ['record' => $d]))])
                    ->send();
            });
    }

    /**
     * Annulation directe par le responsable (décision Q11) : catégorie de motif et pièce de correction « annulation ».
     * Remplace l'ancienne annulation libre de la fiche, avec le même nom d'action.
     */
    public static function annuler(): TableAction
    {
        return TableAction::make('annuler')
            ->label('Annuler')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            // Un versement issu d'un règlement d'organisme ne s'annule qu'avec lui (lot A2, §9.7).
            ->visible(fn (Versement $record): bool => CorrectionsVersements::peutDecider(auth()->user()) && ! $record->estAnnule()
                && $record->reglement_organisme_id === null)
            ->modalHeading('Annuler ce versement')
            ->modalDescription(fn (Versement $record): string => 'Le versement de '.LibelleSituation::fcfa($record->montant).' reste visible, barré, avec votre motif et une pièce de correction numérotée. Sa référence pourra être ressaisie.'
                .($record->recus()->whereDoesntHave('annulation')->exists() ? ' Son reçu reçoit une écriture d\'annulation.' : '')
                .(RegistreVersements::annulationRebloque($record) ? ' Attention : l\'inscription ne serait plus soldée ; le code disparaîtrait de l\'espace de l\'auditeur, mais resterait valable sur eCampus.' : '')
                .(($raison = CorrectionsVersements::raisonRefus($record)) !== null && ! $record->estAnnule() ? ' '.$raison : '')
                .(($d = $record->correctionDemandee()) !== null ? ' Une correction est demandée ('.$d->numero.') : décidez-la depuis l\'écran des demandes.' : ''))
            ->form(fn (Versement $record): array => MessagesValidation::appliquer([
                Forms\Components\Select::make('categorie')->label('Catégorie')->required()->native(false)
                    ->options(array_intersect_key(CorrectionVersement::CATEGORIES, array_flip(['doublon', 'rejet_banque', 'montant', 'auditeur', 'autre']))),
                Forms\Components\Textarea::make('motif')->label('Motif de l\'annulation')->required()->minLength(10)->maxLength(500)->rows(3),
                Forms\Components\Checkbox::make(CorrectionsVersements::OPTION_FERMETURE)->label(CorrectionsVersements::FERMETURE)
                    ->visible(fn (): bool => RegistreVersements::annulationRebloque($record) || $record->confirmation()->exists()),
            ]))
            ->action(function (Versement $record, array $data, TableAction $action): void {
                try {
                    $c = app(CorrectionsVersements::class)->annulerDirectement($record, (string) $data['categorie'], (string) $data['motif'], auth()->user(),
                        [CorrectionsVersements::OPTION_FERMETURE => (bool) ($data[CorrectionsVersements::OPTION_FERMETURE] ?? false)]);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Annulation impossible')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Versement annulé')->body('Pièce de correction '.$c->numero.'.')->send();
            });
    }

    /** Corrige sans demande : qui détient tous les droits du geste (le circuit refuserait sa demande). */
    private static function direct(): bool
    {
        $u = auth()->user();

        return $u instanceof User && CorrectionsVersements::agitDirectement($u);
    }

    /** @return array<int, string> */
    private static function auditeurs(string $search): array
    {
        $motif = '%'.addcslashes(trim($search), '%_\\').'%';

        return Auditeur::query()->whereHas('inscriptionCourante')
            ->whereHas('candidature', fn (Builder $c) => $c->whereNull('withdrawn_at')->where(fn (Builder $w) => $w->where('matricule', 'ilike', $motif)
                ->orWhere('nom', 'ilike', $motif)->orWhere('prenom', 'ilike', $motif)))
            ->limit(self::AUDITEURS_MAX)->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => CorrectionsVersements::afficher('auditeur_id', $id)])->all();
    }
}
