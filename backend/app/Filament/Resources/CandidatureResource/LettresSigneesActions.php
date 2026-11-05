<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Services\DocumentsAuditeurs\LettresAdmissionSignees;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/** Dépôt des lettres d'admission signées : en lot depuis la liste, ou dossier par dossier. */
final class LettresSigneesActions
{
    /** L'application n'a pas de lang/fr/validation.php : sans cela, la clé brute s'affiche. */
    private const MESSAGES = [
        'required' => 'Déposez au moins un fichier.',
        'max' => 'Fichier trop lourd (10 Mo au plus) ou trop de fichiers à la fois.',
        'mimetypes' => 'Le fichier doit être un PDF.',
        'uploaded' => 'L\'envoi de ce fichier a échoué. Réessayez.',
    ];

    private const NOMS_AFFICHES = 15;

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function deposerLot(MountableAction $action): MountableAction
    {
        return $action
            ->label('Déposer les lettres signées')
            ->icon('heroicon-o-document-arrow-up')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can(LettresAdmissionSignees::PERMISSION))
            ->modalHeading('Déposer les lettres d\'admission signées')
            ->modalDescription('Un PDF par auditeur, nommé exactement par son matricule : 26EFP014.pdf. Au plus '.LettresAdmissionSignees::LOT_MAX.' fichiers à la fois. '
                .'Les lettres déjà déposées restent en place : un dépôt interrompu peut être relancé. Aucun message n\'est envoyé aux auditeurs.')
            ->modalSubmitActionLabel('Déposer')
            ->form([
                Forms\Components\Select::make('campagne_id')
                    ->label('Promotion')
                    ->options(fn (): array => self::promotions())
                    ->default(fn () => array_key_first(self::promotions()))
                    ->required()
                    ->native(false),
                Forms\Components\FileUpload::make('fichiers')
                    ->label('Lettres signées (PDF)')
                    ->multiple()
                    ->storeFiles(false)
                    ->required()
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(LettresAdmissionSignees::TAILLE_MAX_KO)
                    ->maxFiles(LettresAdmissionSignees::LOT_MAX)
                    ->maxParallelUploads(3)
                    ->previewable(false)
                    ->validationMessages(self::MESSAGES),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();
                abort_unless($user?->can(LettresAdmissionSignees::PERMISSION), 403);
                $campagne = CampagneCandidature::query()->findOrFail($data['campagne_id']);
                abort_unless($user->can('view', $campagne), 403);

                try {
                    self::notifierBilan(app(LettresAdmissionSignees::class)->deposerLot($campagne, $data['fichiers'], $user));
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Dépôt impossible')->body($e->getMessage())->send();
                } finally {
                    collect($data['fichiers'])->each(fn (TemporaryUploadedFile $f) => $f->delete());
                }
            });
    }

    /**
     * Promotions déjà numérotées, la plus récente d'abord : une campagne sans
     * matricule ne peut recevoir aucune lettre.
     *
     * @return array<int, string>
     */
    public static function promotions(): array
    {
        return CampagneCandidature::query()
            ->whereIn('id', Candidature::query()->whereNotNull('matricule')->select('campagne_id'))
            ->orderByDesc('opens_at')
            ->get(['id', 'promotion_numero', 'slug'])
            ->mapWithKeys(fn (CampagneCandidature $k): array => [$k->id => "Promotion {$k->promotion_numero} ({$k->slug})"])
            ->all();
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function deposer(MountableAction $action): MountableAction
    {
        return $action
            ->label(fn (Candidature $record): string => $record->lettre_admission_path === null ? 'Déposer la lettre signée' : 'Remplacer la lettre signée')
            ->icon('heroicon-o-document-arrow-up')
            ->color('gray')
            ->visible(fn (Candidature $record): bool => (bool) auth()->user()?->can(LettresAdmissionSignees::PERMISSION)
                && LettresAdmissionSignees::depotPossible($record))
            ->modalHeading(fn (Candidature $record): string => 'Lettre signée — '.trim("{$record->prenom} {$record->nom}").' ('.$record->matricule.')')
            ->modalDescription(fn (Candidature $record): string => "Le fichier doit s'appeler {$record->matricule}.pdf. Vérifiez que le scan est bien celui de cette personne : l'auditeur le verra dans son espace dès le dépôt.")
            ->modalSubmitActionLabel('Déposer')
            ->form([
                Forms\Components\FileUpload::make('fichier')
                    ->label('Lettre signée (PDF)')
                    ->storeFiles(false)
                    ->required()
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(LettresAdmissionSignees::TAILLE_MAX_KO)
                    ->previewable(false)
                    ->validationMessages(self::MESSAGES),
            ])
            ->action(function (Candidature $record, array $data): void {
                $user = auth()->user();
                abort_unless($user?->can(LettresAdmissionSignees::PERMISSION), 403);

                try {
                    app(LettresAdmissionSignees::class)->deposer($record, $data['fichier'], $user);
                    Notification::make()->success()->title('Lettre signée déposée')->send();
                } catch (InvalidArgumentException|RuntimeException $e) {
                    Notification::make()->danger()->title('Dépôt impossible')->body($e->getMessage())->send();
                } finally {
                    $data['fichier']->delete();
                }
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function voir(MountableAction $action): MountableAction
    {
        return $action
            ->label('Voir la lettre signée')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (Candidature $record): bool => (bool) auth()->user()?->can(LettresAdmissionSignees::PERMISSION)
                && LettresAdmissionSignees::disponible($record))
            ->action(function (Candidature $record) {
                abort_unless(auth()->user()?->can(LettresAdmissionSignees::PERMISSION), 403);
                $service = app(LettresAdmissionSignees::class);
                $service->journaliserConsultation($record, auth()->user());

                return redirect()->away($service->lienSigne($record));
            });
    }

    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function retirer(MountableAction $action): MountableAction
    {
        return $action
            ->label('Retirer la lettre signée')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Candidature $record): bool => (bool) auth()->user()?->can(LettresAdmissionSignees::PERMISSION)
                && filled($record->lettre_admission_path))
            ->requiresConfirmation()
            ->modalHeading(fn (Candidature $record): string => 'Retirer la lettre signée de '.trim("{$record->prenom} {$record->nom}"))
            ->modalDescription('L\'auditeur ne la verra plus dans son espace, et le fichier est supprimé. À utiliser si elle a été rattachée par erreur.')
            ->modalSubmitActionLabel('Retirer')
            ->action(function (Candidature $record): void {
                abort_unless(auth()->user()?->can(LettresAdmissionSignees::PERMISSION), 403);
                app(LettresAdmissionSignees::class)->retirer($record, auth()->user());
                Notification::make()->success()->title('Lettre signée retirée')->send();
            });
    }

    /** @param  array{deposees: int, deja: list<string>, inconnus: list<string>, invalides: list<string>, echecs: list<string>, manquantes: int}  $bilan */
    private static function notifierBilan(array $bilan): void
    {
        $lignes = array_filter([
            "{$bilan['deposees']} lettre(s) déposée(s). Il en manque encore {$bilan['manquantes']} dans la promotion.",
            self::liste('Déjà déposées, laissées en place', $bilan['deja']),
            self::liste('Nom sans matricule reconnu', $bilan['inconnus']),
            self::liste('Pas un PDF valide de 10 Mo au plus', $bilan['invalides']),
            self::liste('Non enregistrées, à relancer', $bilan['echecs']),
        ]);
        $incident = $bilan['inconnus'] !== [] || $bilan['invalides'] !== [] || $bilan['echecs'] !== [];

        $notification = Notification::make()
            ->title($incident ? 'Dépôt terminé, à vérifier' : 'Dépôt terminé')
            ->body(implode(' ', $lignes))
            ->persistent();
        ($incident ? $notification->warning() : $notification->success())->send();
    }

    /** @param  list<string>  $noms */
    private static function liste(string $titre, array $noms): ?string
    {
        if ($noms === []) {
            return null;
        }
        $affiches = array_map(fn (string $n): string => Str::limit($n, 40), array_slice($noms, 0, self::NOMS_AFFICHES));
        $reste = count($noms) - count($affiches);

        return $titre.' ('.count($noms).') : '.implode(', ', $affiches).($reste > 0 ? " et {$reste} autre(s)." : '.');
    }
}
