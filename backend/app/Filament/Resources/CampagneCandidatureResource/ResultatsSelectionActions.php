<?php

declare(strict_types=1);

namespace App\Filament\Resources\CampagneCandidatureResource;

use App\Models\CampagneCandidature;
use App\Services\ResultatsSelection\ResultatsSelectionExport;
use App\Services\ResultatsSelection\SeanceJury;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement du communiqué des résultats et du PV du jury, en Word ou en
 * PDF, depuis la liste des campagnes et la page d'édition d'une campagne.
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
            ->label('Communiqué des résultats')
            ->icon('heroicon-o-megaphone')
            ->modalHeading('Communiqué conjoint des résultats')
            ->modalDescription(fn (CampagneCandidature $record): string => self::avertissement($record)
                .'Liste des candidats retenus : dossiers soumis en ligne et déposés physiquement. Les champs vides sortent en pointillés, à compléter à la main avant signature.')
            ->form([
                self::format(),
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
            ->label('PV du jury de sélection')
            ->icon('heroicon-o-document-text')
            ->modalHeading('Procès-verbal des délibérations du jury')
            ->modalDescription(fn (CampagneCandidature $record): string => self::avertissement($record)
                .'Composition du jury : config/resultats_selection.php. Les champs vides sortent en pointillés, à compléter à la main avant signature.')
            ->form([
                self::format(),
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
            ->modalSubmitActionLabel('Télécharger')
            ->action(function (CampagneCandidature $record, array $data) use ($document): StreamedResponse {
                $user = auth()->user();
                abort_unless($user?->can(self::PERMISSION) && $user->can('view', $record), 403);

                $fichier = app(ResultatsSelectionExport::class)->generer(
                    $record,
                    $document,
                    (string) $data['format'],
                    SeanceJury::depuisFormulaire($data),
                    $user,
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

    private static function format(): Forms\Components\Radio
    {
        return Forms\Components\Radio::make('format')
            ->label('Format')
            ->options([
                ResultatsSelectionExport::DOCX => 'Word — modifiable avant impression',
                ResultatsSelectionExport::PDF => 'PDF — prêt à imprimer',
            ])
            ->default(ResultatsSelectionExport::PDF)
            ->in([ResultatsSelectionExport::DOCX, ResultatsSelectionExport::PDF])
            ->required()
            ->inline();
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
