<?php

declare(strict_types=1);

namespace App\Filament\Resources\CampagneCandidatureResource;

use App\Models\CampagneCandidature;
use App\Services\ResultatsSelection\Doublon;
use App\Services\ResultatsSelection\ResultatsSelectionExport;
use App\Services\ResultatsSelection\ResultatsSelectionService;
use App\Services\ResultatsSelection\SeanceJury;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement Word du communiqué des résultats et du PV du jury, depuis la
 * liste des campagnes et la page d'édition d'une campagne.
 *
 * Configure indifféremment une action de tableau ou d'en-tête de page : les
 * deux héritent de MountableAction.
 */
final class ResultatsSelectionActions
{
    public const PERMISSION = 'campagne.download_resultats';

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function communique(MountableAction $action): MountableAction
    {
        return self::configurer($action, ResultatsSelectionExport::COMMUNIQUE)
            ->label('Communiqué des résultats (Word)')
            ->icon('heroicon-o-megaphone')
            ->modalHeading('Communiqué conjoint des résultats')
            ->modalDescription(fn (CampagneCandidature $record): string => self::avertissement($record)
                .'Liste des candidats retenus : dossiers soumis en ligne et déposés physiquement. Les champs vides sortent en pointillés, à compléter à la main avant signature.')
            ->form(fn (CampagneCandidature $record): array => [
                ...self::doublons($record),
                self::numero(),
            ]);
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function pv(MountableAction $action): MountableAction
    {
        return self::configurer($action, ResultatsSelectionExport::PV)
            ->label('PV du jury de sélection (Word)')
            ->icon('heroicon-o-document-text')
            ->modalHeading('Procès-verbal des délibérations du jury')
            ->modalDescription(fn (CampagneCandidature $record): string => self::avertissement($record)
                .'Composition du jury : config/resultats_selection.php. Les champs vides sortent en pointillés, à compléter à la main avant signature.')
            ->form(fn (CampagneCandidature $record): array => [
                ...self::doublons($record),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\DatePicker::make('date_deliberation')
                        ->label('Date de la délibération')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                    Forms\Components\TextInput::make('heure_deliberation')
                        ->label('Heure')
                        ->placeholder('10 heures')
                        ->maxLength(30),
                    Forms\Components\TextInput::make('note_service_reference')
                        ->label('Note de service désignant le jury (n°)')
                        ->placeholder('26-812/UYII/FSEG-D')
                        ->maxLength(60),
                    Forms\Components\DatePicker::make('note_service_date')
                        ->label('Date de la note de service')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                ]),
                self::numero(),
            ]);
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configurer(MountableAction $action, string $document): MountableAction
    {
        return $action
            ->color('gray')
            ->visible(fn (CampagneCandidature $record): bool => (bool) auth()->user()?->can(self::PERMISSION)
                && (bool) auth()->user()?->can('view', $record))
            ->modalSubmitActionLabel('Télécharger le document Word')
            ->action(function (CampagneCandidature $record, array $data) use ($document): StreamedResponse {
                $user = auth()->user();
                abort_unless($user?->can(self::PERMISSION) && $user->can('view', $record), 403);

                $fichier = app(ResultatsSelectionExport::class)->generer(
                    $record,
                    $document,
                    SeanceJury::depuisFormulaire($data),
                    $user,
                    conserverDoublons: (bool) ($data['conserver_doublons'] ?? false),
                );

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
     * Signale à l'agent les personnes retenues au titre de plusieurs dossiers
     * et lui laisse le choix de les garder ; par défaut, un seul dossier.
     *
     * @return list<Component>
     */
    private static function doublons(CampagneCandidature $campagne): array
    {
        $doublons = app(ResultatsSelectionService::class)->doublons($campagne);

        if ($doublons === []) {
            return [
                Forms\Components\Placeholder::make('aucun_doublon')
                    ->label('Doublons')
                    ->content('Aucun doublon détecté parmi les candidats retenus.'),
            ];
        }

        $liste = implode('<br>', array_map(fn (Doublon $d): string => e($d->description()), $doublons));

        return [
            Forms\Components\Section::make(count($doublons) === 1 ? '1 doublon détecté' : count($doublons).' doublons détectés')
                ->description('Même nom, mêmes prénoms et même date de naissance sur plusieurs dossiers retenus.')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->compact()
                ->schema([
                    Forms\Components\Placeholder::make('liste_doublons')
                        ->hiddenLabel()
                        ->content(new HtmlString($liste)),
                    Forms\Components\Checkbox::make('conserver_doublons')
                        ->label('Conserver les doublons dans les listes')
                        ->helperText('Décoché : un seul dossier par personne est gardé, celui dont le dossier papier a été reçu en dernier (indiqué « conservé » ci-dessus).')
                        ->default(false),
                ]),
        ];
    }

    /** Les listes d'une campagne encore ouverte peuvent évoluer d'ici la clôture. */
    private static function avertissement(CampagneCandidature $campagne): string
    {
        return $campagne->status === 'open'
            ? 'Attention : la campagne est encore ouverte, les listes peuvent encore évoluer. '
            : '';
    }

    private static function numero(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('numero')
            ->label('Numéro d\'enregistrement')
            ->helperText('Laisser vide si le numéro est attribué au courrier au moment de la signature.')
            ->maxLength(30);
    }
}
