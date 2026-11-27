<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Filament\Resources\DemandeAutorisationResource;
use App\Http\Controllers\Admin\ImprimerRecuController;
use App\Models\DemandeAutorisation;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Scolarite\AvisRecus;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use Filament\Forms;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Actions sur les reçus des versements (Scolarité lot 4), communes à la fiche auditeur et à la liste des reçus.
 * L'émission exige `scolarite.recu_emettre` (service financier) ; l'annulation d'un reçu, toujours suivie de sa
 * réémission, est décidée par un administrateur (règle du 25/11/2026) ; le téléchargement, la lecture.
 */
final class RecuActions
{
    public static function peutEmettre(): bool
    {
        return (bool) auth()->user()?->can(RecusVersements::PERMISSION_EMETTRE);
    }

    public static function emettre(): TableAction
    {
        return TableAction::make('emettreRecu')->label('Émettre le reçu')->icon('heroicon-o-document-check')->color('success')
            ->visible(fn (Versement $record): bool => self::peutEmettre() && app(RecusVersements::class)->raisonRefus($record, auth()->user()) === null)
            ->requiresConfirmation()->modalHeading('Émettre le reçu de ce versement')
            ->modalDescription(fn (Versement $record): string => 'Un reçu numéroté de '.RecusVersements::fcfa($record->montant).' est émis à votre nom. Il ne se modifie plus : une erreur se corrige par une écriture d\'annulation.')
            ->action(function (Versement $record): void {
                $recu = self::executer(fn (): RecuVersement => app(RecusVersements::class)->emettre($record, auth()->user()));
                AuditeurActions::avecImpression(Notification::make()->success()->title('Reçu '.$recu->numero.' émis')->body('L\'auditeur peut le télécharger dans son espace.'), $recu)->send();
            });
    }

    /** Télécharge le dernier reçu du versement (en vigueur, ou annulé avec sa mention). */
    public static function telechargerDepuisVersement(): TableAction
    {
        return TableAction::make('telechargerRecu')->label('Reçu (PDF)')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (Versement $record): bool => $record->recus->isNotEmpty())
            ->action(fn (Versement $record): StreamedResponse => self::reponse($record->recus->sortByDesc('id')->first()));
    }

    /** Annule et réémet le reçu lui-même : qui détient l'annulation et l'émission (en pratique le super administrateur). */
    public static function reemetDirectement(): bool
    {
        $u = auth()->user();

        return (bool) $u?->can(RegistreVersements::PERMISSION_ANNULER) && (bool) $u?->can(RecusVersements::PERMISSION_EMETTRE);
    }

    /** Demande la réémission à un administrateur : le service financier (règle du 25/11/2026). */
    public static function demandeReemission(): bool
    {
        $u = auth()->user();

        return $u instanceof User && ! self::reemetDirectement() && TypesDemande::peutViser($u, TypesDemande::REEMETTRE_RECU);
    }

    /**
     * « Annuler et réémettre le reçu » (administrateur) ou « Demander la réémission du reçu » (service financier, décidée
     * par un administrateur). Jamais d'annulation seule : un versement compté reste toujours couvert par un reçu.
     *
     * @param  \Closure(Model): ?RecuVersement  $recu  reçu en vigueur de la ligne, ou null
     */
    public static function reemettre(\Closure $recu, string $nom = 'reemettreRecu'): TableAction
    {
        return TableAction::make($nom)
            ->label(fn (): string => self::reemetDirectement() ? 'Annuler et réémettre le reçu' : 'Demander la réémission du reçu')
            ->icon('heroicon-o-arrow-path')->color('danger')
            ->visible(fn (Model $record): bool => $recu($record) !== null && (self::reemetDirectement() || self::demandeReemission()))
            ->modalHeading(fn (Model $record): string => (self::reemetDirectement() ? 'Annuler et réémettre le reçu ' : 'Demander la réémission du reçu ').$recu($record)?->numero)
            ->modalDescription(fn (): string => (self::reemetDirectement()
                ? 'Le reçu reçoit une écriture d\'annulation à votre nom et un nouveau reçu numéroté le remplace aussitôt ; l\'auditeur en est prévenu.'
                : 'Votre demande part à un administrateur. À sa décision, le reçu est annulé et un nouveau reçu numéroté, à votre nom, le remplace aussitôt ; l\'auditeur en est prévenu.')
                .' Une erreur sur le versement lui-même (montant, date, référence, auditeur, doublon) se règle par « Corriger ce versement ». '
                .'Pour un reçu simplement non reçu par l\'auditeur, utilisez « Renvoyer le reçu à l\'auditeur ».')
            ->modalSubmitActionLabel(fn (): string => self::reemetDirectement() ? 'Annuler et réémettre' : 'Envoyer la demande')
            ->mountUsing(function (Model $record, TableAction $action) use ($recu): void {
                $r = $recu($record);
                $demande = $r === null ? null : DemandeAutorisation::query()->where('type', TypesDemande::REEMETTRE_RECU)->where('entite_id', $r->id)
                    ->whereIn('statut', DemandeAutorisation::EN_ATTENTE)->value('numero');
                if ($demande !== null) {
                    Notification::make()->danger()->title('Réémission déjà demandée')->body('La demande '.$demande.' attend la décision d\'un administrateur.')->send();
                    $action->cancel();
                }
            })
            ->form(self::formulaireMotif())
            ->action(function (Model $record, array $data) use ($recu): void {
                $r = $recu($record) ?? throw new Halt;
                if (self::reemetDirectement()) {
                    $nouveau = self::executer(fn (): RecuVersement => app(RecusVersements::class)->reemettre($r, (string) $data['motif'], auth()->user(), auth()->user()));
                    AuditeurActions::avecImpression(Notification::make()->success()->title('Reçu '.$nouveau->numero.' émis')
                        ->body('Il remplace le reçu '.$r->numero.', annulé.'), $nouveau)->send();

                    return;
                }
                $d = self::executer(fn (): DemandeAutorisation => app(Demandes::class)->soumettre(auth()->user(), TypesDemande::REEMETTRE_RECU, $r->id, (string) $data['motif']));
                Notification::make()->success()->title('Demande '.$d->numero.' transmise')
                    ->body('Un administrateur la décide. Le reçu '.$r->numero.' reste valable d\'ici là.')
                    ->actions([Action::make('voir')->label('Voir la demande')->url(DemandeAutorisationResource::getUrl('view', ['record' => $d]))])
                    ->send();
            });
    }

    /**
     * « Renvoyer le reçu à l'auditeur » : SMS et e-mail de nouveau, sans rien annuler (le reçu reste téléchargeable dans
     * son espace). Pour l'auditeur qui dit ne pas l'avoir reçu.
     *
     * @param  \Closure(Model): ?RecuVersement  $recu
     */
    public static function renvoyer(\Closure $recu, string $nom = 'renvoyerRecu'): TableAction
    {
        return TableAction::make($nom)->label('Renvoyer le reçu à l\'auditeur')->icon('heroicon-o-paper-airplane')->color('gray')
            ->visible(fn (Model $record): bool => self::peutEmettre() && $recu($record) !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (Model $record): string => 'Renvoyer le reçu '.$recu($record)?->numero)
            ->modalDescription('L\'auditeur reçoit de nouveau le SMS et l\'e-mail du reçu. Rien n\'est annulé ; le reçu reste téléchargeable dans son espace.')
            ->modalSubmitActionLabel('Renvoyer')
            ->action(function (Model $record) use ($recu): void {
                $r = $recu($record) ?? throw new Halt;
                $dossier = $r->versement?->auditeur?->candidature;
                if ($dossier === null) {
                    Notification::make()->danger()->title('Renvoi impossible')->body('Ce reçu n\'est rattaché à aucun dossier d\'auditeur.')->send();

                    return;
                }
                app(AvisRecus::class)->prevenir($r, $dossier, auth()->user());
                $bilan = Activity::query()->where('event', 'recu_avis')->where('subject_type', $r->getMorphClass())->where('subject_id', $r->id)->latest('id')->first()?->properties;
                $envoyes = (int) ($bilan['sms_envoyes'] ?? 0) + (int) ($bilan['emails_envoyes'] ?? 0);
                Notification::make()->status($envoyes > 0 ? 'success' : 'warning')
                    ->title($envoyes > 0 ? 'Reçu '.$r->numero.' renvoyé' : 'Reçu '.$r->numero.' : avis non envoyé')
                    ->body('SMS envoyés : '.(int) ($bilan['sms_envoyes'] ?? 0).' · e-mails envoyés : '.(int) ($bilan['emails_envoyes'] ?? 0).'.')->send();
            });
    }

    /** @return list<Forms\Components\Component> */
    public static function formulaireMotif(): array
    {
        return MessagesValidation::appliquer([
            Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500)->rows(3),
        ], ['min' => 'Le motif compte au moins 10 caractères.']);
    }

    /** Adresse de l'aperçu imprimable du reçu (nouvel onglet), ou null si l'agent ne peut pas le lire. */
    public static function urlImpression(?RecuVersement $recu): ?string
    {
        return $recu === null || ! ImprimerRecuController::peutImprimer(auth()->user()) ? null : route('filament.admin.recus.imprimer', $recu);
    }

    /** « Imprimer le reçu » : aperçu A4 dans un nouvel onglet, d'où l'agent imprime aussitôt. */
    public static function imprimer(\Closure $recu, string $nom = 'imprimerRecu'): TableAction
    {
        return TableAction::make($nom)->label('Imprimer le reçu')->icon('heroicon-o-printer')->color('gray')
            ->visible(fn (Model $record): bool => self::urlImpression($recu($record)) !== null)
            ->url(fn (Model $record): ?string => self::urlImpression($recu($record)))
            ->openUrlInNewTab();
    }

    public static function reponse(RecuVersement $recu): StreamedResponse
    {
        $pdf = app(RecusVersements::class)->pdf($recu);
        activity('scolarite')->causedBy(auth()->user())->performedOn($recu)->event('recu_telecharge')
            ->withProperties(['numero' => $recu->numero])->log('Reçu téléchargé dans l\'administration');

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf;
        }, $recu->nomFichier(), ['Content-Type' => 'application/pdf']);
    }

    /**
     * Exécute une opération du service ; un refus motivé garde la fenêtre ouverte.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function executer(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }
}
