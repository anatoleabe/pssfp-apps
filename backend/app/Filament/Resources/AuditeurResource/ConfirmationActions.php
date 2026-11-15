<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Versement;
use App\Services\Scolarite\ConfirmationsVersements;
use App\Services\Scolarite\LibelleSituation;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use InvalidArgumentException;

/**
 * Confirmation d'un versement par la scolarité (décision du 15/11/2026), commune à l'onglet Versements de
 * la fiche et à l'écran « Versements à confirmer » : scan du bordereau bancaire et attestation exigés,
 * jamais par l'agent qui a saisi le versement. ConfirmationsVersements revérifie tout.
 */
final class ConfirmationActions
{
    public static function confirmer(string $nom = 'confirmerVersement'): TableAction
    {
        return TableAction::make($nom)
            ->label('Confirmer le versement')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (Versement $record): bool => ConfirmationsVersements::raisonRefus($record, auth()->user()) === null)
            ->modalHeading(fn (Versement $record): string => 'Confirmer le versement de '.LibelleSituation::fcfa($record->montant))
            ->modalDescription(fn (Versement $record): string => 'Bordereau '.$record->reference.' du '.$record->date_versement->format('d/m/Y')
                .'. Vérifiez le reçu bancaire original présenté par l\'auditeur, puis scannez-le. La confirmation est définitive : une erreur se corrige par l\'annulation du versement.'
                .($record->reglement_organisme_id !== null ? ' Part d\'un règlement d\'organisme : toutes les parts encore à confirmer de ce règlement le sont, sur la pièce bancaire du règlement.' : ''))
            ->modalSubmitActionLabel('Confirmer')
            ->form(MessagesValidation::appliquer([
                AuditeurActions::champJustificatif(
                    libelle: 'Scan du bordereau bancaire',
                    siPanne: 'Le stockage n\'a pas répondu : le scan n\'est pas enregistré. Réessayez avant de confirmer.',
                )->required(),
                Forms\Components\Checkbox::make('attestation')->label(ConfirmationsVersements::ATTESTATION)->accepted(),
            ], ['accepted' => 'Cochez l\'attestation : la confirmation l\'exige.']))
            ->action(function (Versement $record, array $data, TableAction $action): void {
                $chemin = $data['justificatif_path'] ?? null;
                try {
                    AuditeurActions::exigerScanDepose($chemin);
                    $resultat = app(ConfirmationsVersements::class)->confirmer($record, $chemin, (bool) ($data['attestation'] ?? false), auth()->user());
                } catch (InvalidArgumentException $e) {
                    AuditeurActions::supprimerScanOrphelin($chemin);
                    Notification::make()->danger()->title('Versement non confirmé')->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }
                $n = count($resultat->confirmations);
                Notification::make()->success()
                    ->title($n > 1 ? $n.' parts du règlement confirmées' : 'Versement confirmé')
                    ->body(match ($resultat->sms) {
                        true => 'Droits d\'inscription soldés : l\'auditeur a été prévenu de ses accès par SMS et par e-mail.',
                        false => 'Droits d\'inscription soldés, mais ni le SMS ni l\'e-mail des accès ne sont partis : utilisez « Renvoyer le SMS et l\'e-mail » sur la fiche.',
                        null => 'L\'argent compte désormais pour l\'accès et la situation « à jour » de l\'auditeur.',
                    })
                    ->send();
            });
    }

    /** Colonne « Confirmation » : jour et agent, ou « À confirmer ». */
    public static function etat(Versement $v): string
    {
        return match (true) {
            $v->confirmation !== null => 'Confirmé le '.$v->confirmation->confirme_at->setTimezone('Africa/Douala')->format('d/m/Y'),
            $v->estAnnule() => '—',
            default => 'À confirmer',
        };
    }
}
