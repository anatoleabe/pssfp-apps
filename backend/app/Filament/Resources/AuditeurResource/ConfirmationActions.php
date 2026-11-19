<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Versement;
use App\Services\Scolarite\ConfirmationsVersements;
use App\Services\Scolarite\LibelleSituation;
use App\Support\CandidatureObjectStorage;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Confirmation d'un versement par la scolarité (décision du 15/11/2026), commune à l'onglet Versements de
 * la fiche et à l'écran « Versements à confirmer » : attestation exigée, jamais par l'agent qui a saisi le
 * versement. Depuis le 19/11/2026, l'agent compare le reçu original au scan du service financier, sans en
 * déposer un autre ; il ne scanne que s'il n'y en a pas. ConfirmationsVersements revérifie tout.
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
            ->modalDescription(fn (Versement $record): string => self::consigne($record))
            ->modalSubmitActionLabel('Confirmer')
            ->form(fn (Versement $record): array => MessagesValidation::appliquer([
                Forms\Components\Placeholder::make('saisie')->label('Saisi par le service financier')
                    ->content(LibelleSituation::fcfa($record->montant).' · bordereau '.$record->reference.' · versé le '.$record->date_versement->format('d/m/Y')),
                Forms\Components\Placeholder::make('scan_finances')->label('Scan du service financier')
                    ->visible($record->scanServiceFinancier() !== null)
                    ->content(fn (): HtmlString => self::lienScan($record)),
                AuditeurActions::champJustificatif(
                    libelle: 'Scan du bordereau bancaire',
                    siPanne: 'Le stockage n\'a pas répondu : le scan n\'est pas enregistré. Réessayez avant de confirmer.',
                )->required()->visible($record->scanServiceFinancier() === null),
                Forms\Components\Checkbox::make('attestation')->label(ConfirmationsVersements::attestation($record))->accepted(),
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

    private static function consigne(Versement $v): string
    {
        $definitive = ' La confirmation est définitive. Si le reçu ne concorde pas, ne confirmez pas : signalez l\'écart au service financier, qui demandera la correction.';
        if ($v->reglement_organisme_id !== null) {
            return 'Part d\'un règlement d\'organisme : toutes les parts encore à confirmer de ce règlement le sont ensemble.'
                .($v->scanServiceFinancier() !== null ? ' Ouvrez la pièce bancaire du règlement et vérifiez-la.' : ' Le service financier n\'a pas joint de pièce : scannez la pièce bancaire du règlement.')
                .$definitive;
        }

        return ($v->scanServiceFinancier() !== null
            ? 'Comparez le reçu bancaire original présenté par l\'auditeur au scan du service financier : montant, référence et date. Rien à scanner.'
            : 'Le service financier n\'a pas joint le scan du bordereau : vérifiez le reçu bancaire original présenté par l\'auditeur, puis scannez-le.')
            .$definitive;
    }

    /** Lien signé 30 minutes vers le scan du service financier, ouvert dans un nouvel onglet (la CSP de l'admin n'affiche pas d'image du stockage). */
    private static function lienScan(Versement $v): HtmlString
    {
        $chemin = (string) $v->scanServiceFinancier();
        $url = Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl($chemin, now()->addMinutes(30),
            ['ResponseContentDisposition' => 'inline; filename="bordereau.'.pathinfo($chemin, PATHINFO_EXTENSION).'"']);

        return new HtmlString(Blade::render(
            '<x-filament::link :href="$url" target="_blank" icon="heroicon-m-arrow-top-right-on-square" icon-position="after">{{ $libelle }}</x-filament::link>',
            ['url' => $url, 'libelle' => $v->reglement_organisme_id !== null ? 'Ouvrir la pièce du règlement' : 'Ouvrir le scan du bordereau'],
        ));
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
