<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Auditeur;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\NotificationAcces;
use App\Services\Scolarite\OuvertureAuditeur;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\ResultatOperation;
use App\Support\CandidatureObjectStorage;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Gestes de la Scolarité sur une fiche auditeur. Chaque action ouvre un petit
 * formulaire dédié, n'apparaît qu'avec la permission du geste, et délègue à
 * RegistreVersements (qui revérifie la permission).
 */
final class AuditeurActions
{
    public const DOSSIER_JUSTIFICATIFS = 'scolarite/justificatifs';

    public static function enregistrerVersement(): Action
    {
        return Action::make('enregistrerVersement')
            ->label('Enregistrer un versement')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_SAISIR)
                && $record->inscriptionCourante !== null && $record->candidature?->withdrawn_at === null)
            ->modalHeading('Enregistrer un versement')
            ->modalDescription(fn (Auditeur $record): string => 'Reste à payer : '.LibelleSituation::fcfa($record->inscriptionCourante->situation()->reste)
                .'. Le versement compte aussitôt ; seul le responsable de scolarité peut l\'annuler.')
            ->modalSubmitActionLabel('Enregistrer')
            ->form(fn (Auditeur $record): array => MessagesValidation::appliquer([
                Forms\Components\TextInput::make('montant')->label('Montant (FCFA)')->required()->integer()->minValue(1)->maxValue(100000000)->live(onBlur: true),
                Forms\Components\DatePicker::make('date_versement')->label('Date du versement')->required()->native(false)
                    ->displayFormat('d/m/Y')->default(InscriptionAnnuelle::aujourdhui()->toDateString())
                    ->maxDate(InscriptionAnnuelle::aujourdhui()->toDateString()),
                Forms\Components\Select::make('mode')->label('Mode de paiement')->required()->options(Versement::MODES)->default('cremincam_agence')->native(false),
                Forms\Components\TextInput::make('reference')->label('Référence du bordereau')->required()->maxLength(100),
                Forms\Components\Select::make('payeur')->label('Payé par')->required()->options(Versement::PAYEURS)->default('auditeur')->native(false),
                self::champJustificatif(),
                Forms\Components\Checkbox::make('confirmer_trop_percu')
                    ->label('Je confirme : ce montant dépasse le reste à payer.')
                    ->visible(fn (Forms\Get $get): bool => (int) $get('montant') > $record->inscriptionCourante->situation()->reste)
                    ->accepted(),
            ]))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    $resultat = app(RegistreVersements::class)->enregistrer($record->inscriptionCourante, $data, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Versement non enregistré')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                self::notifier('Versement enregistré', $resultat);
            });
    }

    public static function accorderRemise(): Action
    {
        return Action::make('accorderRemise')
            ->label('Accorder une remise')
            ->icon('heroicon-o-receipt-percent')
            ->color('gray')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_REMISE)
                && $record->inscriptionCourante !== null && $record->candidature?->withdrawn_at === null)
            ->modalHeading('Remise sur l\'inscription')
            ->modalDescription('Bourse, exonération : la remise réduit d\'abord la 2e tranche, puis la 1re, puis l\'inscription. 0 retire la remise. Une prise en charge par l\'employeur s\'enregistre comme un versement.')
            ->fillForm(fn (Auditeur $record): array => ['montant' => $record->inscriptionCourante->remise_montant])
            ->form(fn (Auditeur $record): array => MessagesValidation::appliquer([
                Forms\Components\TextInput::make('montant')->label('Montant de la remise (FCFA)')->required()->integer()->minValue(0)
                    ->maxValue($record->inscriptionCourante->montant_inscription + $record->inscriptionCourante->montant_tranche1 + $record->inscriptionCourante->montant_tranche2)
                    ->live(onBlur: true)
                    ->helperText(fn (Forms\Get $get): ?string => RegistreVersements::remiseRebloque($record->inscriptionCourante, (int) $get('montant'))
                        ? 'Attention : avec cette remise, l\'inscription ne serait plus soldée et le code disparaîtrait de l\'espace (il resterait valable sur eCampus).'
                        : null),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->maxLength(500)->rows(3),
            ]))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    $resultat = app(RegistreVersements::class)->accorderRemise($record->inscriptionCourante, (int) $data['montant'], (string) $data['motif'], auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Remise non enregistrée')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                self::notifier('Remise enregistrée', $resultat);
            });
    }

    public static function renvoyerSms(): Action
    {
        return Action::make('renvoyerSms')
            ->label('Renvoyer le SMS')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('warning')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_SAISIR)
                && $record->candidature !== null
                && AccesEcampus::etat($record->candidature) === AccesEcampus::DISPONIBLE
                && $record->inscriptionCourante?->acces_notifie_at === null)
            ->requiresConfirmation()
            ->modalDescription('Le SMS « vos accès sont disponibles dans votre espace » n\'est pas parti. Il ne contient pas de mot de passe.')
            ->action(function (Auditeur $record): void {
                $envoye = app(NotificationAcces::class)->siDisponible($record->candidature);
                $envoye === true
                    ? Notification::make()->success()->title('SMS envoyé')->send()
                    : Notification::make()->danger()->title('SMS non envoyé')->body('La passerelle n\'a pas répondu. Réessayez plus tard ; si cela persiste, prévenez l\'USI.')->persistent()->send();
            });
    }

    public static function creerInscription(): Action
    {
        return Action::make('creerInscription')
            ->label('Créer l\'inscription')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_SAISIR) && $record->inscriptionCourante === null)
            ->requiresConfirmation()
            ->modalDescription('Inscription en Master 1 pour l\'année de la promotion, au barème en vigueur.')
            ->action(function (Auditeur $record): void {
                $inscription = app(OuvertureAuditeur::class)->creerInscription($record);
                $inscription === null
                    ? Notification::make()->danger()->title('Barème manquant')->body('Aucun barème M1 pour l\'année de cette promotion : un responsable de scolarité doit d\'abord le saisir (Vie académique > Barèmes).')->persistent()->send()
                    : Notification::make()->success()->title('Inscription créée')->send();
            });
    }

    public static function annulerVersement(): TableAction
    {
        return TableAction::make('annuler')
            ->label('Annuler')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Versement $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_ANNULER) && ! $record->estAnnule())
            ->modalHeading('Annuler ce versement')
            ->modalDescription(fn (Versement $record): string => 'Le versement de '.LibelleSituation::fcfa($record->montant).' reste visible, barré, avec votre motif. Sa référence pourra être ressaisie.'
                .(RegistreVersements::annulationRebloque($record) ? ' Attention : l\'inscription ne serait plus soldée ; le code disparaîtrait de l\'espace de l\'auditeur, mais resterait valable sur eCampus.' : ''))
            ->form(MessagesValidation::appliquer([
                Forms\Components\Textarea::make('motif')->label('Motif de l\'annulation')->required()->maxLength(500)->rows(3),
            ]))
            ->action(function (Versement $record, array $data, TableAction $action): void {
                try {
                    app(RegistreVersements::class)->annuler($record, (string) $data['motif'], auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Annulation impossible')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Versement annulé')->send();
            });
    }

    public static function voirJustificatif(): TableAction
    {
        return TableAction::make('voirJustificatif')
            ->label('Bordereau')
            ->icon('heroicon-o-paper-clip')
            ->color('gray')
            ->visible(fn (Versement $record): bool => filled($record->justificatif_path))
            ->url(fn (Versement $record): string => Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl(
                (string) $record->justificatif_path,
                now()->addMinutes(30),
                ['ResponseContentDisposition' => 'inline; filename="bordereau"'],
            ), shouldOpenInNewTab: true);
    }

    public static function ajouterJustificatif(): TableAction
    {
        return TableAction::make('ajouterJustificatif')
            ->label('Joindre le bordereau')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (Versement $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_SAISIR)
                && blank($record->justificatif_path) && ! $record->estAnnule())
            ->form(MessagesValidation::appliquer([self::champJustificatif()->required()]))
            ->action(function (Versement $record, array $data): void {
                if (blank($data['justificatif_path'] ?? null)) {
                    return;
                }
                $record->forceFill(['justificatif_path' => $data['justificatif_path']])->save();
                activity('scolarite')->causedBy(auth()->user())->performedOn($record)->event('justificatif_joint')->log('Bordereau joint au versement');
                Notification::make()->success()->title('Bordereau joint')->send();
            });
    }

    /**
     * Scan facultatif, stockage privé. Une panne de stockage n'empêche pas
     * d'enregistrer le versement : l'agent est averti et joindra le scan ensuite.
     */
    private static function champJustificatif(): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make('justificatif_path')
            ->label('Scan du bordereau (facultatif)')
            ->disk(CandidatureObjectStorage::DISK)
            ->directory(self::DOSSIER_JUSTIFICATIFS)
            ->visibility('private')
            ->fetchFileInformation(false)
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
            ->maxSize(5120)
            ->helperText('PDF, JPG ou PNG, 5 Mo maximum.')
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): ?string {
                $nom = Str::ulid().'.'.($file->guessExtension() ?: 'pdf');
                try {
                    $chemin = $file->storeAs(self::DOSSIER_JUSTIFICATIFS, $nom, ['disk' => CandidatureObjectStorage::DISK]);

                    return $chemin === false ? throw new \RuntimeException('Écriture refusée.') : $chemin;
                } catch (Throwable $e) {
                    Log::channel('single')->error('Bordereau non enregistré.', ['erreur' => $e::class]);
                    Notification::make()->warning()->persistent()->title('Bordereau non enregistré')
                        ->body('Le stockage n\'a pas répondu. Le versement sera enregistré sans scan : joignez-le ensuite depuis la liste des versements.')->send();

                    return null;
                }
            });
    }

    private static function notifier(string $titre, ResultatOperation $resultat): void
    {
        match ($resultat->sms) {
            true => Notification::make()->success()->title($titre)->body('Inscription soldée : l\'auditeur a été prévenu par SMS que ses accès sont dans son espace.')->send(),
            false => Notification::make()->warning()->persistent()->title($titre)
                ->body('Inscription soldée, mais le SMS « accès disponibles » n\'est pas parti. Utilisez « Renvoyer le SMS » sur la fiche.')->send(),
            null => Notification::make()->success()->title($titre)->send(),
        };
    }
}
