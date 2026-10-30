<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource;

use App\Models\Candidature;
use App\Services\EspaceCandidat\NouveauCodeCandidat;
use App\Services\EspaceCandidat\TropDeNouveauxCodes;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Accès de l'administration à l'espace d'un candidat, depuis la liste des
 * candidatures et la fiche d'un dossier : voir son espace en lecture seule,
 * et lui donner un nouveau code de connexion. Réservé à la permission
 * candidature.espace_candidat (admin, super_admin).
 */
final class EspaceCandidatActions
{
    public const PERMISSION = NouveauCodeCandidat::PERMISSION;

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function voirEspace(MountableAction $action): MountableAction
    {
        // Le lien d'ouverture (usage unique) est créé par la route au clic,
        // jamais au rendu de la liste.
        return self::autorise($action)
            ->label('Voir son espace')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->url(fn (Candidature $record): string => route('filament.admin.candidatures.espace', $record))
            ->openUrlInNewTab();
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function nouveauCode(MountableAction $action): MountableAction
    {
        return self::autorise($action)
            ->label('Nouveau code de connexion')
            ->icon('heroicon-o-lock-open')
            ->color('gray')
            ->modalHeading(fn (Candidature $record): string => 'Nouveau code de connexion — '.trim("{$record->prenom} {$record->nom}"))
            ->modalDescription(fn (Candidature $record): string => 'Un nouveau code à 6 chiffres remplace l\'actuel : l\'ancien cesse de fonctionner '
                .'et le candidat est déconnecté. Le code vous est affiché une seule fois. Compte : '
                .(NouveauCodeCandidat::compteCandidat($record)?->phone_e164 ?? '—')
                .($record->email ? ' · '.$record->email : '').'.')
            ->form([
                Forms\Components\Radio::make('canal')
                    ->label('Envoyer le code par')
                    // Sans adresse e-mail au dossier, seules les options sans e-mail sont proposées.
                    ->options(fn (Candidature $record): array => array_filter([
                        NouveauCodeCandidat::CANAL_SMS => 'SMS',
                        NouveauCodeCandidat::CANAL_EMAIL => $record->email ? 'E-mail' : null,
                        NouveauCodeCandidat::CANAL_LES_DEUX => $record->email ? 'SMS et e-mail' : null,
                        NouveauCodeCandidat::CANAL_AUCUN => 'Ne pas envoyer (je le communique moi-même)',
                    ]))
                    ->default(NouveauCodeCandidat::CANAL_SMS)
                    ->required(),
            ])
            ->modalSubmitActionLabel('Générer le code')
            ->action(function (Candidature $record, array $data): void {
                abort_unless(auth()->user()?->can(self::PERMISSION), 403);
                try {
                    $resultat = app(NouveauCodeCandidat::class)->generer($record, (string) $data['canal'], auth()->user());
                } catch (TropDeNouveauxCodes $e) {
                    Notification::make()->warning()->title('Aucun nouveau code')->body($e->getMessage())->send();

                    return;
                }
                self::notifier($resultat);
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
            && NouveauCodeCandidat::compteCandidat($record) !== null);
    }

    /** @param  array{code: string, sms: ?bool, email: ?bool}  $r */
    private static function notifier(array $r): void
    {
        $envois = collect(['sms' => 'SMS', 'email' => 'E-mail'])
            ->filter(fn (string $libelle, string $canal): bool => $r[$canal] !== null)
            ->map(fn (string $libelle, string $canal): string => $libelle.($r[$canal] ? ' envoyé' : ' en échec'))
            ->implode(' · ');
        $echec = $r['sms'] === false || $r['email'] === false;

        $notification = Notification::make()
            ->title('Nouveau code : '.$r['code'])
            ->body(($envois !== '' ? $envois.'. ' : 'Aucun envoi : communiquez-le vous-même. ')
                .'Notez-le maintenant : il ne sera plus affiché.')
            // Reste à l'écran jusqu'à fermeture : l'agent doit pouvoir le recopier.
            ->persistent();
        ($echec ? $notification->warning() : $notification->success())->send();
    }
}
