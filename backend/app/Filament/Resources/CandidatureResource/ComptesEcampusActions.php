<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource;

use App\Models\Candidature;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use Filament\Actions\MountableAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/** Création ou reprise du compte eCampus depuis la liste et la fiche du dossier. */
final class ComptesEcampusActions
{
    public const LIBELLES = [
        ComptesEcampus::CREE => 'Créé',
        ComptesEcampus::EXISTANT => 'Existant',
        ComptesEcampus::COHORTE_A_FINIR => 'Cohorte à finir',
        ComptesEcampus::ECHEC => 'Échec',
    ];

    /** Libellé de la colonne ; null hors retenus numérotés. */
    public static function libelle(Candidature $c): ?string
    {
        if (in_array($c->ecampus_statut, [ComptesEcampus::CREE, ComptesEcampus::EXISTANT], true)) {
            return self::LIBELLES[$c->ecampus_statut];
        }
        // Dossier retiré entre-temps : plus rien à reprendre, donc plus d'alerte.
        if (! IdentifiantsMoodle::disponibles($c)) {
            return null;
        }

        return self::LIBELLES[$c->ecampus_statut] ?? 'À créer';
    }

    public static function couleur(Candidature $c): string
    {
        return match ($c->ecampus_statut) {
            ComptesEcampus::CREE => 'success',
            ComptesEcampus::EXISTANT => $c->ecampus_detail === null ? 'info' : 'warning',
            ComptesEcampus::COHORTE_A_FINIR => 'warning',
            ComptesEcampus::ECHEC => 'danger',
            default => 'gray',
        };
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function creer(MountableAction $action): MountableAction
    {
        return $action
            ->label('Créer le compte eCampus')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn (Candidature $record): bool => ComptesEcampus::actif()
                && (bool) auth()->user()?->can(IdentifiantsMoodle::PERMISSION)
                && ComptesEcampus::aTraiter($record))
            ->requiresConfirmation()
            ->modalHeading('Créer le compte eCampus')
            ->modalDescription('Le compte reçoit l\'identifiant et le mot de passe déjà attribués, sans message à l\'auditeur. Un compte déjà présent n\'est pas modifié.')
            ->modalSubmitActionLabel('Créer')
            ->action(function (Candidature $record): void {
                abort_unless(auth()->user()?->can(IdentifiantsMoodle::PERMISSION), 403);
                self::notifier(app(ComptesEcampus::class)->traiterPlusieurs(collect([$record]), auth()->user()), $record->ecampus_detail);
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function creerGroupe(MountableAction $action): MountableAction
    {
        return $action
            ->label('Créer les comptes eCampus')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn (): bool => ComptesEcampus::actif() && (bool) auth()->user()?->can(IdentifiantsMoodle::PERMISSION))
            ->requiresConfirmation()
            ->modalDescription('Seuls les retenus dotés d\'un matricule et sans compte confirmé sont traités, sans message à l\'auditeur. Si la sélection est longue, relancez pour traiter les dossiers restants.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                abort_unless(auth()->user()?->can(IdentifiantsMoodle::PERMISSION), 403);
                $dossiers = $records->load('campagne')->filter(fn (Candidature $c): bool => ComptesEcampus::aTraiter($c))->values();
                self::notifier(app(ComptesEcampus::class)->traiterPlusieurs($dossiers, auth()->user()));
            });
    }

    /**
     * Phrase ajoutée au message d'attribution ; vide sans jeton configuré.
     *
     * @param  array{crees: int, existants: int, echecs: int, restants: int}|null  $bilan
     */
    public static function phrase(?array $bilan): string
    {
        if ($bilan === null) {
            return '';
        }

        return "eCampus : {$bilan['crees']} compte(s) créé(s), {$bilan['existants']} existant(s), "
            ."{$bilan['echecs']} échec(s), {$bilan['restants']} restant(s).";
    }

    /** @param  array{crees: int, existants: int, echecs: int, restants: int}|null  $bilan */
    public static function sansIncident(?array $bilan): bool
    {
        return $bilan === null || ($bilan['echecs'] === 0 && $bilan['restants'] === 0);
    }

    /** @param  array{crees: int, existants: int, echecs: int, restants: int}|null  $bilan */
    private static function notifier(?array $bilan, ?string $detail = null): void
    {
        $notification = Notification::make()
            ->title(self::sansIncident($bilan) ? 'Comptes eCampus traités' : 'Comptes eCampus à reprendre')
            ->body(trim(self::phrase($bilan).' '.($detail ?? '')));
        (self::sansIncident($bilan) ? $notification->success() : $notification->warning())->send();
    }
}
