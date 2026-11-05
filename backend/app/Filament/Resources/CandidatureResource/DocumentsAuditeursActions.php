<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Services\DocumentsAuditeurs\DocumentImpossible;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\DocumentsAuditeurs\FraisInscription;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use Filament\Actions\MountableAction;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Documents remis aux auditeurs (accès FOAD, lettres d'admission) au format
 * Word, une page par auditeur : pour toute la promotion depuis la campagne,
 * pour une sélection ou un seul dossier depuis les candidatures.
 */
final class DocumentsAuditeursActions
{
    private const LIBELLES = [
        ParametresLettres::DOCUMENT_ACCES => 'Accès FOAD (Word)',
        ParametresLettres::DOCUMENT_ADMISSION => 'Lettres d\'admission (Word)',
    ];

    /**
     * Toute la promotion, depuis la campagne.
     *
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function campagne(MountableAction $action, string $document): MountableAction
    {
        return $action
            ->label(self::LIBELLES[$document])
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn (CampagneCandidature $record): bool => (bool) auth()->user()?->can(DocumentsAuditeurs::permission($document))
                && (bool) auth()->user()?->can('view', $record))
            ->requiresConfirmation($document === ParametresLettres::DOCUMENT_ACCES)
            ->modalHeading(self::LIBELLES[$document])
            ->modalDescription(self::avertissement($document))
            ->modalSubmitActionLabel('Télécharger')
            ->action(fn (CampagneCandidature $record): ?StreamedResponse => self::telecharger($record, $document, null));
    }

    /**
     * Un seul dossier, depuis la liste ou la fiche.
     *
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function dossier(MountableAction $action, string $document): MountableAction
    {
        return $action
            ->label($document === ParametresLettres::DOCUMENT_ACCES ? 'Lettre d\'accès FOAD (Word)' : 'Lettre d\'admission (Word)')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn (Candidature $record): bool => (bool) auth()->user()?->can(DocumentsAuditeurs::permission($document))
                && ($document === ParametresLettres::DOCUMENT_ACCES ? IdentifiantsMoodle::remettables($record)
                    : FraisInscription::concerne($record) && $record->matricule !== null))
            ->action(fn (Candidature $record): ?StreamedResponse => $record->campagne === null ? null
                : self::telecharger($record->campagne, $document, [$record->id]));
    }

    /** Sélection de dossiers d'une même promotion. */
    public static function selection(BulkAction $action, string $document): BulkAction
    {
        return $action
            ->label(self::LIBELLES[$document])
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can(DocumentsAuditeurs::permission($document)))
            ->requiresConfirmation($document === ParametresLettres::DOCUMENT_ACCES)
            ->modalDescription(self::avertissement($document))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($document): ?StreamedResponse {
                $campagnes = $records->pluck('campagne_id')->unique();
                if ($campagnes->count() !== 1 || ($campagne = CampagneCandidature::find($campagnes->first())) === null) {
                    Notification::make()->warning()->title('Une seule promotion à la fois')
                        ->body('Sélectionnez des dossiers d\'une même campagne.')->send();

                    return null;
                }

                return self::telecharger($campagne, $document, $records->pluck('id')->map(fn ($id): int => (int) $id)->all());
            });
    }

    private static function avertissement(string $document): ?string
    {
        return $document === ParametresLettres::DOCUMENT_ACCES
            ? 'Seuls les auditeurs dont l\'inscription est soldée y figurent. Ce fichier contient leurs mots de passe, qui ne changent jamais : ne le transmettez pas, ne le déposez sur aucun partage, détruisez les impressions inutiles. Le téléchargement est inscrit sur la fiche de chaque auditeur.'
            : null;
    }

    /** @param  list<int>|null  $seulement */
    private static function telecharger(CampagneCandidature $campagne, string $document, ?array $seulement): ?StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user?->can(DocumentsAuditeurs::permission($document)) && $user->can('view', $campagne), 403);

        try {
            $fichier = app(DocumentsAuditeurs::class)->generer($campagne, $document, $user, $seulement);
        } catch (DocumentImpossible $e) {
            // Action sans fenêtre : notifier suffit (halt() la croirait ouverte).
            Notification::make()->danger()->title('Document impossible à générer')->body($e->getMessage())->persistent()->send();

            return null;
        } catch (Throwable $e) {
            // Panne de stockage ou autre : le détail (adresse interne MinIO…) reste dans les journaux.
            Log::channel('single')->error('Génération des documents des auditeurs échouée.', ['document' => $document, 'erreur' => $e::class]);
            Notification::make()->danger()->title('Document impossible à générer')
                ->body('Erreur technique pendant la génération. Réessayez dans quelques instants ; si elle persiste, prévenez l\'USI.')->persistent()->send();

            return null;
        }

        return response()->streamDownload(function () use ($fichier): void {
            echo $fichier['contenu'];
        }, $fichier['nom'], ['Content-Type' => $fichier['mime']]);
    }
}
