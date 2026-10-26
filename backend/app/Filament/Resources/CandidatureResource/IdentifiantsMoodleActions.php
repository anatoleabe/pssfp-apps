<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource;

use App\Models\Candidature;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Collection;

/**
 * Identifiants FOAD (Moodle) d'un auditeur, depuis la liste des candidatures
 * et la fiche d'un dossier : consultation et envoi par SMS et/ou e-mail.
 * Réservé à l'administration (permission candidature.matricules_moodle).
 */
final class IdentifiantsMoodleActions
{
    public const PERMISSION = 'candidature.matricules_moodle';

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function consulter(MountableAction $action): MountableAction
    {
        return self::autorise($action)
            ->label('Identifiants Moodle')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->modalHeading(fn (Candidature $record): string => 'Identifiants Moodle — '.trim("{$record->prenom} {$record->nom}"))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            // Une consultation = une ligne de journal, au montage du modal (pas à chaque rendu).
            ->mountUsing(function (Candidature $record): void {
                abort_unless(auth()->user()?->can(self::PERMISSION), 403);
                app(IdentifiantsMoodle::class)->journaliserConsultation($record, auth()->user());
            })
            ->modalContent(fn (Candidature $record) => view('filament.candidature.identifiants-moodle', [
                'foad' => config('pssfp.foad_url'),
                'identifiant' => IdentifiantsMoodle::identifiant($record),
                'motDePasse' => (string) $record->moodle_password,
            ]));
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function envoyer(MountableAction $action): MountableAction
    {
        return self::autorise($action)
            ->label('Envoyer les identifiants Moodle')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->modalHeading('Envoyer les identifiants Moodle au candidat')
            ->modalDescription(fn (Candidature $record): string => 'Destinataire : '.trim(($record->phone_e164 ?? '').($record->email ? ' · '.$record->email : '')).'. Le SMS compte un seul segment.')
            ->form([self::canal()])
            ->modalSubmitActionLabel('Envoyer')
            ->action(function (Candidature $record, array $data): void {
                abort_unless(auth()->user()?->can(self::PERMISSION), 403);
                self::notifier(collect([app(IdentifiantsMoodle::class)->envoyer($record, (string) $data['canal'], auth()->user())]));
            });
    }

    public static function envoyerGroupe(BulkAction $action): BulkAction
    {
        return $action
            ->label('Envoyer les identifiants Moodle')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can(self::PERMISSION))
            ->requiresConfirmation()
            ->modalHeading('Envoyer leurs identifiants Moodle aux candidats sélectionnés')
            ->modalDescription('Seuls les retenus dotés d\'un matricule les reçoivent ; les autres dossiers sélectionnés sont ignorés. Un SMS par candidat. '
                .'Au plus '.IdentifiantsMoodle::ENVOI_GROUPE_MAX.' dossiers par envoi : procédez par lots.')
            ->form([self::canal()])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                abort_unless(auth()->user()?->can(self::PERMISSION), 403);
                if ($records->count() > IdentifiantsMoodle::ENVOI_GROUPE_MAX) {
                    Notification::make()->warning()
                        ->title('Sélection trop large')
                        ->body('Au plus '.IdentifiantsMoodle::ENVOI_GROUPE_MAX.' dossiers par envoi ('.$records->count().' sélectionnés) : la requête dépasserait le délai du serveur. Procédez par lots.')
                        ->send();
                    $action->halt();

                    return;
                }
                $service = app(IdentifiantsMoodle::class);
                self::notifier($records
                    ->filter(fn (Candidature $c): bool => IdentifiantsMoodle::disponibles($c))
                    ->map(fn (Candidature $c): array => $service->envoyer($c, (string) $data['canal'], auth()->user()))
                    ->values());
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    private static function autorise(MountableAction $action): MountableAction
    {
        return $action->visible(fn (Candidature $record): bool => (bool) auth()->user()?->can(self::PERMISSION)
            && IdentifiantsMoodle::disponibles($record));
    }

    private static function canal(): Forms\Components\Radio
    {
        return Forms\Components\Radio::make('canal')
            ->label('Envoyer par')
            ->options([
                IdentifiantsMoodle::CANAL_SMS => 'SMS',
                IdentifiantsMoodle::CANAL_EMAIL => 'E-mail',
                IdentifiantsMoodle::CANAL_LES_DEUX => 'SMS et e-mail',
            ])
            ->default(IdentifiantsMoodle::CANAL_LES_DEUX)
            ->inline()
            ->required();
    }

    /** @param  Collection<int, array{sms: ?bool, email: ?bool}>  $resultats */
    private static function notifier(Collection $resultats): void
    {
        $compter = fn (string $canal, bool $etat): int => $resultats->filter(fn (array $r): bool => $r[$canal] === $etat)->count();
        $echecs = $compter('sms', false) + $compter('email', false);

        $notification = Notification::make()
            ->title($resultats->isEmpty() ? 'Aucun envoi : aucun retenu doté d\'un matricule' : 'Identifiants envoyés')
            ->body(sprintf('SMS : %d envoyé(s), %d échec(s). E-mails : %d envoyé(s), %d échec(s).',
                $compter('sms', true), $compter('sms', false), $compter('email', true), $compter('email', false)));
        ($echecs > 0 || $resultats->isEmpty() ? $notification->warning() : $notification->success())->send();
    }
}
