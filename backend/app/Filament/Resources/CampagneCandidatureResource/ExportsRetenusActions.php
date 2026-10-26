<?php

declare(strict_types=1);

namespace App\Filament\Resources\CampagneCandidatureResource;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\ExportsRetenus;
use App\Services\ExportsRetenus\ExportsRetenusExcel;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Matricules des retenus et fichiers prêts à importer (données complètes,
 * ACMA, Moodle), depuis la liste des campagnes et la page d'une campagne.
 */
final class ExportsRetenusActions
{
    public const PERMISSION = 'campagne.export_retenus';

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function attribuer(MountableAction $action): MountableAction
    {
        return self::autorise($action)
            ->label('Attribuer les matricules')
            ->icon('heroicon-o-identification')
            ->requiresConfirmation()
            ->modalHeading('Attribuer les matricules des retenus')
            ->modalDescription(fn (CampagneCandidature $record): string => self::bilan($record))
            ->modalSubmitActionLabel('Attribuer')
            ->form(fn (CampagneCandidature $record): array => [
                Forms\Components\TextInput::make('annee')
                    ->label('Année du matricule (deux chiffres)')
                    ->default(AttributionMatricules::anneeParDefaut($record))
                    ->required()
                    ->regex('/^\d{2}$/')
                    ->helperText('« 26 » donne 26EFP001, 26EFSC002… Les matricules déjà attribués ne changent pas.'),
            ])
            ->action(function (CampagneCandidature $record, array $data): void {
                $user = auth()->user();
                abort_unless($user?->can(self::PERMISSION) && $user->can('view', $record), 403);

                $r = app(AttributionMatricules::class)->attribuer($record, (string) $data['annee'], $user);

                Notification::make()
                    ->success()
                    ->title($r['attribues'] === 0 ? 'Aucun nouveau matricule' : "{$r['attribues']} matricule(s) attribué(s)")
                    ->body("{$r['deja']} retenu(s) avaient déjà leur matricule, inchangé.")
                    ->send();
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function fullData(MountableAction $action): MountableAction
    {
        return self::telecharger($action, ExportsRetenusExcel::FULL_DATA)
            ->label('Données complètes (Excel)')
            ->icon('heroicon-o-table-cells')
            ->modalDescription('Une ligne par retenu, toutes les informations du dossier, avec matricule et n° LETTRENUMERO.');
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function acma(MountableAction $action): MountableAction
    {
        return self::telecharger($action, ExportsRetenusExcel::ACMA)
            ->label('Importation ACMA (Excel)')
            ->icon('heroicon-o-academic-cap')
            ->modalDescription('Format d\'import du logiciel de scolarité ACMA. Valeurs fixes (Exp, Parcours, Catégorie, Session) : config/exports_retenus.php.');
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function moodle(MountableAction $action): MountableAction
    {
        return self::telecharger($action, ExportsRetenusExcel::MOODLE)
            ->label('Comptes Moodle (Excel)')
            ->icon('heroicon-o-computer-desktop')
            ->modalDescription('Comptes de la plateforme FOAD : identifiant = matricule en minuscules, mot de passe initial figé à l\'attribution. Fichier sensible : à ne transmettre qu\'à l\'administrateur Moodle.')
            ->form(fn (CampagneCandidature $record): array => [
                Forms\Components\TextInput::make('cohorte')
                    ->label('Cohorte Moodle (cohort1)')
                    ->default(ExportsRetenus::cohorteParDefaut($record))
                    ->required()
                    ->maxLength(100),
            ]);
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    private static function telecharger(MountableAction $action, string $type): MountableAction
    {
        return self::autorise($action)
            ->modalHeading(fn (CampagneCandidature $record): string => ExportsRetenusExcel::nomFeuille($type, $record))
            ->modalSubmitActionLabel('Télécharger')
            ->action(function (CampagneCandidature $record, array $data, MountableAction $action) use ($type): ?StreamedResponse {
                $user = auth()->user();
                abort_unless($user?->can(self::PERMISSION) && $user->can('view', $record), 403);

                $manquants = ExportsRetenus::sansMatricule($record);
                if ($manquants > 0) {
                    Notification::make()->danger()
                        ->title('Matricules à attribuer d\'abord')
                        ->body("{$manquants} retenu(s) n'ont pas encore de matricule : lancez « Attribuer les matricules ».")
                        ->send();
                    $action->halt();

                    return null;
                }

                $fichier = app(ExportsRetenus::class)->generer($record, $type, $user, (string) ($data['cohorte'] ?? ''));

                return response()->streamDownload(
                    function () use ($fichier): void {
                        echo $fichier['contenu'];
                    },
                    $fichier['nom'],
                    ['Content-Type' => $fichier['mime']],
                );
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
        return $action
            ->color('gray')
            ->visible(fn (CampagneCandidature $record): bool => (bool) auth()->user()?->can(self::PERMISSION)
                && (bool) auth()->user()?->can('view', $record));
    }

    private static function bilan(CampagneCandidature $record): string
    {
        $retenus = AttributionMatricules::retenus($record);
        $total = (clone $retenus)->count();
        $sans = (clone $retenus)->whereNull('matricule')->count();
        $efsc = (clone $retenus)->whereNull('matricule')->where('categorie_matricule', Candidature::CATEGORIE_EFSC)->count();

        return "{$total} retenu(s), dont {$sans} sans matricule ({$efsc} classé(s) EFSC, les autres EFP). "
            .'Numérotation dans l\'ordre alphabétique, à la suite des matricules existants. '
            .'Vérifiez le classement EFP / EFSC avant : une fois attribué, un matricule ne change plus.';
    }
}
