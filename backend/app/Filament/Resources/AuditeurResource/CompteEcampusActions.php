<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Http\Controllers\Admin\OuvrirCompteEcampusController;
use App\Models\Auditeur;
use App\Services\Ecampus\EchecEcampus;
use App\Services\Ecampus\MotsDePasseEcampus;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Compte eCampus réel de l'auditeur, depuis sa fiche (19/11/2026), réservé à l'administrateur : vérifier le compte,
 * en réinitialiser le mot de passe à la demande de l'auditeur, ouvrir sa fiche sur eCampus pour s'y connecter en
 * tant que lui.
 */
final class CompteEcampusActions
{
    public static function verifier(): Action
    {
        return Action::make('verifierEcampus')
            ->label('Vérifier sur eCampus')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->visible(fn (Auditeur $record): bool => self::autorise($record))
            ->modalHeading('Compte eCampus de l\'auditeur')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->modalContent(function (Auditeur $record) {
                abort_unless(self::autorise($record), 403);
                $dossier = $record->candidature;
                try {
                    $compte = app(MotsDePasseEcampus::class)->compte($dossier);
                    $erreur = null;
                } catch (EchecEcampus $e) {
                    [$compte, $erreur] = [null, $e->getMessage()];
                }

                return view('filament.scolarite.compte-ecampus', [
                    'compte' => $compte,
                    'erreur' => $erreur,
                    'identifiantAttendu' => IdentifiantsMoodle::identifiant($dossier),
                    'motDePasseConnu' => filled($dossier->moodle_password),
                ]);
            });
    }

    public static function reinitialiser(): Action
    {
        return Action::make('reinitialiserMotDePasseEcampus')
            ->label('Réinitialiser le mot de passe eCampus')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (Auditeur $record): bool => self::autorise($record))
            ->requiresConfirmation()
            ->modalHeading('Réinitialiser le mot de passe eCampus')
            ->modalDescription('À faire seulement à la demande de l\'auditeur. Un nouveau mot de passe simple (6 caractères, minuscules et chiffres) remplace l\'actuel sur eCampus et dans le portail : l\'ancien ne fonctionnera plus. La lettre d\'accès suit.')
            ->modalSubmitActionLabel('Réinitialiser')
            ->action(function (Auditeur $record, Page $livewire): void {
                abort_unless(self::autorise($record), 403);
                try {
                    app(MotsDePasseEcampus::class)->reinitialiser($record->candidature, auth()->user());
                } catch (EchecEcampus $e) {
                    Notification::make()->title('Mot de passe non modifié')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Mot de passe eCampus réinitialisé')
                    ->body('Communiquez-le à l\'auditeur : il est affiché dans la fenêtre des identifiants.')->success()->send();
                // Les identifiants à jour, relus en base : le mot de passe ne transite pas par l'action.
                $livewire->replaceMountedAction('identifiantsMoodle');
            });
    }

    public static function ouvrir(): Action
    {
        return Action::make('ouvrirEcampus')
            ->label('Ouvrir sur eCampus (se connecter en tant que)')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->visible(fn (Auditeur $record): bool => self::autorise($record))
            ->url(fn (Auditeur $record): string => route('filament.admin.auditeurs.ecampus', ['auditeur' => $record]))
            ->openUrlInNewTab();
    }

    private static function autorise(Auditeur $record): bool
    {
        return $record->candidature !== null && filled($record->candidature->matricule)
            && OuvrirCompteEcampusController::peutOuvrir(auth()->user());
    }
}
