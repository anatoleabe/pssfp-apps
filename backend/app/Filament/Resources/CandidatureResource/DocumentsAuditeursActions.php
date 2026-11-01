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
 * pour une sélection ou un seul dossier depuis les candidatures. Et pointage
 * des frais d'inscription, condition de la lettre dans l'espace de l'auditeur.
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
                && ($document === ParametresLettres::DOCUMENT_ACCES ? IdentifiantsMoodle::disponibles($record)
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

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function fraisPayes(MountableAction $action, bool $payes): MountableAction
    {
        return $action
            ->label($payes ? 'Frais d\'inscription payés' : 'Annuler : frais d\'inscription non payés')
            ->icon($payes ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
            ->color($payes ? 'success' : 'gray')
            ->visible(fn (Candidature $record): bool => (bool) auth()->user()?->can(FraisInscription::PERMISSION)
                && FraisInscription::concerne($record) && FraisInscription::payes($record) !== $payes)
            ->requiresConfirmation()
            ->modalDescription($payes
                ? 'À cocher une fois les frais d\'inscription et péri-académiques réglés. La lettre d\'admission pourra alors être mise à disposition de l\'auditeur.'
                : 'Le pointage est retiré ; la lettre d\'admission ne sera plus mise à disposition de l\'auditeur.')
            ->action(fn (Candidature $record) => self::pointer(collect([$record]), $payes));
    }

    public static function fraisPayesGroupe(BulkAction $action, bool $payes): BulkAction
    {
        return $action
            ->label($payes ? 'Frais d\'inscription payés' : 'Frais d\'inscription non payés')
            ->icon($payes ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
            ->color($payes ? 'success' : 'gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can(FraisInscription::PERMISSION))
            ->requiresConfirmation()
            ->modalDescription('Seuls les retenus non retirés sont concernés ; les autres dossiers sélectionnés sont ignorés.')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::pointer($records, $payes));
    }

    private static function avertissement(string $document): ?string
    {
        return $document === ParametresLettres::DOCUMENT_ACCES
            ? 'Ce fichier contient les mots de passe des auditeurs, qui ne changent jamais : ne le transmettez pas, ne le déposez sur aucun partage, détruisez les impressions inutiles. Le téléchargement est inscrit sur la fiche de chaque auditeur.'
            : null;
    }

    /** @param  Collection<int, Candidature>  $dossiers */
    private static function pointer(Collection $dossiers, bool $payes): void
    {
        abort_unless(auth()->user()?->can(FraisInscription::PERMISSION), 403);
        $modifies = app(FraisInscription::class)->pointer($dossiers, $payes, auth()->user());
        Notification::make()->success()
            ->title($payes ? 'Frais d\'inscription pointés' : 'Pointage annulé')
            ->body("{$modifies} dossier(s) mis à jour.")
            ->send();
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
