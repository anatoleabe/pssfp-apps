<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Auditeur;
use App\Models\InscriptionAnnuelle;
use App\Models\LienLettreAcces;
use App\Models\Versement;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\NotificationCandidatsService;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\AnnulationMultiAnnees;
use App\Services\Scolarite\CompteAuditeur;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\LiensLettreAcces;
use App\Services\Scolarite\MessagesAuditeurs;
use App\Services\Scolarite\NotificationAcces;
use App\Services\Scolarite\OuvertureAuditeur;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\ResultatOperation;
use App\Services\Scolarite\StatutAuditeur;
use App\Support\CandidatureObjectStorage;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Collection;
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

    /**
     * Chemins écrits par le champ de pièce pendant cette requête. L'état d'un champ de
     * fichier se modifie depuis le navigateur : seuls ces chemins se suppriment sur un
     * refus, ou se rattachent à un versement ou à un accès anticipé.
     *
     * @var list<string>
     */
    private static array $deposes = [];

    public static function depose(?string $chemin): bool
    {
        return $chemin !== null && in_array($chemin, self::$deposes, true);
    }

    /**
     * Le service financier enregistre et émet le reçu en un seul geste (décision du 15/11/2026) ; sans le droit
     * d'émettre (administrateurs), le geste n'enregistre que le versement.
     */
    public static function encaisseAvecRecu(): bool
    {
        return (bool) auth()->user()?->can(RecusVersements::PERMISSION_EMETTRE);
    }

    public static function enregistrerVersement(): Action
    {
        return Action::make('enregistrerVersement')
            ->label(fn (): string => self::encaisseAvecRecu() ? 'Enregistrer et émettre le reçu' : 'Enregistrer un versement')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_SAISIR)
                && $record->inscriptionCourante !== null && $record->candidature?->withdrawn_at === null)
            ->modalHeading(fn (): string => self::encaisseAvecRecu() ? 'Enregistrer le versement et émettre son reçu' : 'Enregistrer un versement')
            // Reste sur le parcours (arriérés compris), égal en D1 au reste de l'année courante.
            ->modalDescription(fn (Auditeur $record): string => 'Reste à payer : '.LibelleSituation::fcfa(CompteAuditeur::pour($record)->resteParcours())
                .'. Le versement compte aussitôt ; seul le responsable de scolarité peut l\'annuler.'
                .(self::encaisseAvecRecu() ? ' Le reçu numéroté est émis à votre nom et l\'auditeur en est prévenu par SMS et par e-mail.' : ''))
            ->modalSubmitActionLabel(fn (): string => self::encaisseAvecRecu() ? 'Enregistrer et émettre le reçu' : 'Enregistrer')
            ->form(fn (Auditeur $record): array => MessagesValidation::appliquer([
                Forms\Components\TextInput::make('montant')->label('Montant (FCFA)')->required()->integer()->minValue(1)->maxValue(RegistreVersements::MONTANT_MAX)->live(onBlur: true),
                Forms\Components\DatePicker::make('date_versement')->label('Date du versement')->required()->native(false)
                    ->displayFormat('d/m/Y')->default(InscriptionAnnuelle::aujourdhui()->toDateString())
                    // Fin de journée : le sélecteur du navigateur renvoie une heure, et une
                    // borne à minuit refusait la date du jour (constaté au navigateur).
                    ->maxDate(InscriptionAnnuelle::aujourdhui()->endOfDay()->toDateTimeString()),
                Forms\Components\Select::make('mode')->label('Mode de paiement')->required()->options(Versement::MODES)->default('cremincam_agence')->native(false),
                Forms\Components\TextInput::make('reference')->label('Référence du bordereau')->required()->maxLength(100),
                Forms\Components\Select::make('payeur')->label('Payé par')->required()->options(Versement::PAYEURS)->default('auditeur')->native(false)->live(),
                self::champJustificatif(),
                Forms\Components\Checkbox::make('confirmer_avance')
                    ->label(fn (Forms\Get $get): string => self::libelleAvance($record, (int) $get('montant'), (string) $get('payeur')))
                    ->visible(fn (Forms\Get $get): bool => self::avanceAnnoncee($record, (int) $get('montant'), (string) $get('payeur')) > 0)
                    ->accepted(),
            ]))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    self::exigerScanDepose($data['justificatif_path'] ?? null);
                    $registre = app(RegistreVersements::class);
                    $resultat = self::encaisseAvecRecu()
                        ? $registre->encaisser($record->inscriptionCourante, $data, auth()->user())
                        : $registre->enregistrer($record->inscriptionCourante, $data, auth()->user());
                } catch (InvalidArgumentException $e) {
                    self::supprimerScanOrphelin($data['justificatif_path'] ?? null);
                    Notification::make()->danger()->title('Versement non enregistré')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                self::notifier($resultat->recu === null ? 'Versement enregistré' : 'Versement enregistré, reçu '.$resultat->recu->numero.' émis', $resultat);
            });
    }

    /** Part du montant qui dépasse ce que le versement règle et ira en avance (R2) : tout le parcours, ou l'année désignée pour un employeur. */
    private static function avanceAnnoncee(Auditeur $record, int $montant, string $payeur): int
    {
        return max(0, $montant - CompteAuditeur::pour($record)->resteRegle($payeur, $record->inscriptionCourante));
    }

    private static function libelleAvance(Auditeur $record, int $montant, string $payeur): string
    {
        $avance = LibelleSituation::fcfa(self::avanceAnnoncee($record, $montant, $payeur));

        return in_array($payeur, Versement::PAYEURS_RESERVES, true)
            ? 'Je confirme : '.$avance.' dépassent le reste à payer et resteront en avance, en attente de la décision du responsable.'
            : 'Je confirme : '.$avance.' dépassent le reste à payer et iront en avance sur la scolarité à venir.';
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
                $record->refresh();
                self::notifier('Remise enregistrée', $resultat);
            });
    }

    /** Renvoi des accès et du lien vers la lettre : la scolarité, depuis que la saisie est au service financier (15/11/2026). */
    public static function peutRenvoyerAcces(): bool
    {
        return (bool) auth()->user()?->can(DocumentsAuditeurs::PERMISSION_LETTRE_ACCES_DOSSIER);
    }

    public static function renvoyerSms(): Action
    {
        return Action::make('renvoyerSms')
            ->label('Renvoyer le SMS et l\'e-mail')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('warning')
            ->visible(fn (Auditeur $record): bool => self::peutRenvoyerAcces()
                && $record->candidature !== null
                && AccesEcampus::etat($record->candidature) === AccesEcampus::DISPONIBLE
                && $record->inscriptionCourante?->acces_notifie_at === null)
            ->requiresConfirmation()
            ->modalDescription('Ni le SMS ni l\'e-mail des accès ne sont partis. Ils contiennent un lien vers la lettre d\'accès (date de naissance demandée), ou à défaut l\'adresse de l\'espace ; jamais de mot de passe.')
            ->action(function (Auditeur $record): void {
                abort_unless(self::peutRenvoyerAcces(), 403);
                $envoye = app(NotificationAcces::class)->siDisponible($record->candidature, auth()->user());
                $record->refresh();
                match ($envoye) {
                    true => Notification::make()->success()->title('Accès envoyés (SMS et e-mail)')->send(),
                    false => Notification::make()->danger()->title('Ni SMS ni e-mail envoyé')->body('La passerelle n\'a pas répondu. Réessayez plus tard ; si cela persiste, prévenez l\'USI.')->persistent()->send(),
                    null => Notification::make()->info()->title('Rien à envoyer')->body('L\'accès n\'est plus disponible, ou le SMS vient de partir.')->send(),
                };
            });
    }

    /**
     * Nouveau lien vers la lettre d'accès (SMS) : l'ancien est révoqué. Pour un
     * auditeur qui a laissé passer les 7 jours, ou dont le lien s'est verrouillé.
     */
    public static function envoyerLienLettre(): Action
    {
        return Action::make('envoyerLienLettre')
            ->label('Envoyer un nouveau lien')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->visible(fn (Auditeur $record): bool => self::peutRenvoyerAcces()
                && $record->candidature !== null
                && LiensLettreAcces::possible($record->candidature))
            ->requiresConfirmation()
            ->modalHeading('Envoyer un nouveau lien vers la lettre d\'accès')
            ->modalDescription(fn (Auditeur $record): string => 'SMS au '.($record->candidature?->phone_e164 ?? '—').' et e-mail à '.($record->candidature?->email ?: '—').' : lien valable '
                .LienLettreAcces::DUREE_JOURS.' jours, date de naissance demandée avant le téléchargement. Le lien précédent cesse de fonctionner.')
            ->action(function (Auditeur $record): void {
                abort_unless(self::peutRenvoyerAcces(), 403);
                $envoye = app(LiensLettreAcces::class)->envoyer($record->candidature, auth()->user());
                $envoye
                    ? Notification::make()->success()->title('Lien envoyé')->send()
                    : Notification::make()->danger()->title('Ni SMS ni e-mail envoyé')->body('La passerelle SMS et le serveur d\'e-mails n\'ont pas répondu, ou le dossier n\'a ni téléphone ni e-mail. Réessayez plus tard ; si cela persiste, prévenez l\'USI.')->persistent()->send();
            });
    }

    public static function creerInscription(): Action
    {
        return Action::make('creerInscription')
            ->label('Créer l\'inscription')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_SAISIR)
                && $record->inscriptionCourante === null && $record->candidature?->withdrawn_at === null)
            ->requiresConfirmation()
            ->modalDescription('Inscription en Master 1 pour l\'année de la promotion, au barème en vigueur.')
            ->action(function (Auditeur $record): void {
                try {
                    $inscription = app(OuvertureAuditeur::class)->creerInscription($record, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Inscription non créée')->body($e->getMessage())->send();

                    return;
                }
                $record->refresh();
                $inscription === null
                    ? Notification::make()->danger()->title('Barème manquant')->body('Aucun barème M1 pour l\'année de cette promotion : un responsable de scolarité doit d\'abord le saisir (Vie académique > Barèmes).')->persistent()->send()
                    : Notification::make()->success()->title('Inscription créée')->send();
            });
    }

    public static function changerStatut(): Action
    {
        return Action::make('changerStatut')
            ->label('Changer le statut')
            ->icon('heroicon-o-flag')
            ->visible(fn (): bool => (bool) auth()->user()?->can(StatutAuditeur::PERMISSION))
            ->modalHeading('Statut de l\'auditeur')
            ->modalDescription('Informatif : le statut ne bloque ni les versements ni l\'accès à eCampus. Il n\'a pas d\'effet sur le dossier de candidature.')
            ->fillForm(fn (Auditeur $record): array => ['statut' => $record->statut, 'depuis' => InscriptionAnnuelle::aujourdhui()->toDateString()])
            ->form(MessagesValidation::appliquer([
                Forms\Components\Select::make('statut')->label('Statut')->required()->options(Auditeur::STATUTS)->native(false),
                Forms\Components\DatePicker::make('depuis')->label('Date d\'effet')->required()->native(false)->displayFormat('d/m/Y')
                    ->maxDate(InscriptionAnnuelle::aujourdhui()->endOfDay()->toDateTimeString()),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->maxLength(500)->rows(3),
            ]))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    app(StatutAuditeur::class)->changer($record, (string) $data['statut'], (string) $data['motif'], (string) $data['depuis'], auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Statut non modifié')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                Notification::make()->success()->title('Statut modifié')->send();
            });
    }

    public static function message(): BulkAction
    {
        return BulkAction::make('messageAuditeurs')
            ->label('Envoyer un message')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->visible(fn (): bool => (bool) auth()->user()?->can(MessagesAuditeurs::PERMISSION))
            ->modalHeading('Message aux auditeurs sélectionnés')
            ->modalDescription('Variables : '.implode(' ', MessagesAuditeurs::VARIABLES).'. Au plus '.MessagesAuditeurs::MAX.' destinataires ; les dossiers retirés, exclus ou en abandon sont écartés. Chaque message est tracé et apparaît dans l\'espace de l\'auditeur.')
            ->modalSubmitActionLabel('Envoyer')
            ->form(fn (): array => MessagesValidation::appliquer([
                Forms\Components\Select::make('canal')->label('Canal')->required()->native(false)->live()
                    ->options([
                        NotificationCandidatsService::CANAL_SMS => 'SMS',
                        NotificationCandidatsService::CANAL_EMAIL => 'E-mail',
                        NotificationCandidatsService::CANAL_LES_DEUX => 'SMS et e-mail',
                    ])->default(NotificationCandidatsService::CANAL_SMS),
                Forms\Components\TextInput::make('sujet')->label('Objet de l\'e-mail')->maxLength(150)
                    ->visible(fn (Forms\Get $get): bool => $get('canal') !== NotificationCandidatsService::CANAL_SMS)
                    ->required(fn (Forms\Get $get): bool => $get('canal') !== NotificationCandidatsService::CANAL_SMS)
                    ->default('PSSFP — votre scolarité'),
                Forms\Components\Textarea::make('corps')->label('Message')->required()->maxLength(1000)->rows(4)->live(onBlur: true)
                    ->default(MessagesAuditeurs::MODELE_RELANCE),
                Forms\Components\Placeholder::make('apercu')->label('Aperçu')
                    // La sélection n'est pas injectée dans les champs du formulaire : on la relit sur la table.
                    ->content(function (Forms\Get $get, HasTable $livewire): string {
                        $selection = $livewire->getSelectedTableRecords();
                        if ($selection->count() > MessagesAuditeurs::MAX) {
                            return $selection->count().' sélectionnés : au plus '.MessagesAuditeurs::MAX.' par envoi, procédez par lots.';
                        }
                        $a = app(MessagesAuditeurs::class)->apercu(MessagesAuditeurs::precharger($selection), (string) $get('canal'), (string) $get('corps'));

                        return $a['destinataires'].' destinataire(s)'
                            .($a['ecartes'] > 0 ? ' ('.$a['ecartes'].' écarté(s) : dossier retiré, exclusion ou abandon)' : '')
                            .', '.$a['sms_par_message'].' SMS par message, '.$a['cout_sms'].' SMS au total'
                            .($a['hors_couverture'] > 0 ? ', dont '.$a['hors_couverture'].' numéro(s) hors du Cameroun, que la passerelle ne dessert pas' : '')
                            .'. Exemple : « '.$a['exemple'].' »';
                    }),
            ]))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, BulkAction $action): void {
                try {
                    $rapport = app(MessagesAuditeurs::class)->envoyer(
                        $records,
                        (string) $data['canal'], (string) $data['corps'], $data['sujet'] ?? null, auth()->user(),
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Message non envoyé')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Message envoyé')
                    ->body($rapport['sms_envoyes'].' SMS et '.$rapport['emails_envoyes'].' e-mail(s) envoyés'
                        .($rapport['sms_echecs'] + $rapport['emails_echecs'] > 0 ? ', '.($rapport['sms_echecs'] + $rapport['emails_echecs']).' échec(s)' : '')
                        .($rapport['ignores'] > 0 ? ', '.$rapport['ignores'].' écarté(s) ou sans coordonnée' : '').'.')
                    ->send();
            });
    }

    public static function annulerVersement(): TableAction
    {
        return TableAction::make('annuler')
            ->label('Annuler')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            // Un versement issu d'un règlement d'organisme ne s'annule qu'avec lui (lot A2, §9.7).
            ->visible(fn (Versement $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_ANNULER) && ! $record->estAnnule()
                && $record->reglement_organisme_id === null)
            ->modalHeading('Annuler ce versement')
            ->modalDescription(fn (Versement $record): string => 'Le versement de '.LibelleSituation::fcfa($record->montant).' reste visible, barré, avec votre motif. Sa référence pourra être ressaisie.'
                .($record->recus()->whereDoesntHave('annulation')->exists() ? ' Son reçu reçoit une écriture d\'annulation.' : '')
                .(RegistreVersements::annulationRebloque($record) ? ' Attention : l\'inscription ne serait plus soldée ; le code disparaîtrait de l\'espace de l\'auditeur, mais resterait valable sur eCampus.' : '')
                // Refus R4.1 de D1, annoncé avant la saisie du motif.
                .(count($annees = RegistreVersements::anneesTouchees($record)) > 1 ? ' '.(new AnnulationMultiAnnees($annees))->getMessage() : ''))
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
            // Seuls les chemins du dossier des bordereaux sont signés (cf. Versement::cheminJustificatifValide).
            ->visible(fn (Versement $record): bool => Versement::cheminJustificatifValide($record->justificatif_path))
            ->action(function (Versement $record) {
                abort_unless(Versement::cheminJustificatifValide($record->justificatif_path) && auth()->user()?->can('view_auditeur'), 403);
                activity('scolarite')->causedBy(auth()->user())->performedOn($record)->event('justificatif_consulte')->log('Bordereau consulté');
                $extension = pathinfo((string) $record->justificatif_path, PATHINFO_EXTENSION);

                return redirect()->away(Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl(
                    (string) $record->justificatif_path,
                    now()->addMinutes(30),
                    ['ResponseContentDisposition' => 'inline; filename="bordereau.'.$extension.'"'],
                ));
            });
    }

    public static function ajouterJustificatif(): TableAction
    {
        return TableAction::make('ajouterJustificatif')
            ->label('Joindre le bordereau')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (Versement $record): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_SAISIR)
                && blank($record->justificatif_path) && ! $record->estAnnule())
            ->form(MessagesValidation::appliquer([self::champJustificatif()->label('Scan du bordereau')->required()]))
            ->action(function (Versement $record, array $data, TableAction $action): void {
                $chemin = $data['justificatif_path'] ?? null;
                if (blank($chemin)) {
                    Notification::make()->danger()->title('Bordereau non joint')->body('Le fichier n\'a pas pu être enregistré. Réessayez.')->send();
                    $action->halt();

                    return;
                }
                try {
                    self::exigerScanDepose((string) $chemin);
                    app(RegistreVersements::class)->joindreJustificatif($record, (string) $chemin, auth()->user());
                } catch (InvalidArgumentException $e) {
                    self::supprimerScanOrphelin((string) $chemin);
                    Notification::make()->danger()->title('Bordereau non joint')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title('Bordereau joint')->send();
            });
    }

    /**
     * Scan facultatif, stockage privé. Une panne de stockage n'empêche pas
     * d'enregistrer le versement : l'agent est averti et joindra le scan ensuite.
     * Repris pour la pièce d'un accès anticipé (même dossier, 10 Mo).
     */
    public static function champJustificatif(
        string $libelle = 'Scan du bordereau (facultatif)',
        int $maxMo = 5,
        string $siPanne = 'Le stockage n\'a pas répondu : le scan n\'est pas enregistré. Vous pourrez le joindre ensuite depuis la liste des versements.',
        string $champ = 'justificatif_path',
    ): Forms\Components\FileUpload {
        return Forms\Components\FileUpload::make($champ)
            ->label($libelle)
            ->disk(CandidatureObjectStorage::DISK)
            ->directory(self::DOSSIER_JUSTIFICATIFS)
            ->visibility('private')
            ->fetchFileInformation(false)
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
            ->maxSize($maxMo * 1024)
            ->helperText('PDF, JPG ou PNG, '.$maxMo.' Mo maximum.')
            // L'état d'un champ de fichier se modifie depuis le navigateur : on ne signe
            // jamais un chemin qui n'est pas un bordereau (même bucket que les pièces).
            ->getUploadedFileUsing(fn (string $file): ?array => Versement::cheminJustificatifValide($file) ? [
                'name' => basename($file), 'size' => 0, 'type' => null,
                'url' => Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl($file, now()->addMinutes(5)),
            ] : null)
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file) use ($siPanne): ?string {
                $extension = $file->guessExtension();
                $nom = Str::ulid().'.'.(in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true) ? $extension : 'pdf');
                try {
                    $chemin = $file->storeAs(self::DOSSIER_JUSTIFICATIFS, $nom, ['disk' => CandidatureObjectStorage::DISK]);
                    if ($chemin === false) {
                        throw new \RuntimeException('Écriture refusée.');
                    }
                    self::$deposes[] = $chemin;

                    return $chemin;
                } catch (Throwable $e) {
                    Log::channel('single')->error('Bordereau non enregistré.', ['erreur' => $e::class]);
                    Notification::make()->warning()->persistent()->title('Pièce non enregistrée')->body($siPanne)->send();

                    return null;
                }
            });
    }

    /**
     * Seul un scan déposé par ce formulaire, dans cette requête, se rattache au
     * versement : jamais un chemin recopié dans l'état du formulaire.
     *
     * @throws InvalidArgumentException
     */
    private static function exigerScanDepose(?string $chemin): void
    {
        if (filled($chemin) && ! self::depose($chemin)) {
            throw new InvalidArgumentException('Le scan du bordereau est invalide : déposez-le à nouveau.');
        }
    }

    /** Un scan déposé pour un versement (ou un déblocage) refusé ne doit pas rester dans le bucket. */
    public static function supprimerScanOrphelin(?string $chemin): void
    {
        // Jamais un chemin recopié dans l'état du formulaire : ce peut être la preuve d'un autre dossier.
        if (! Versement::cheminJustificatifValide($chemin) || ! self::depose($chemin)) {
            return;
        }
        try {
            Storage::disk(CandidatureObjectStorage::DISK)->delete((string) $chemin);
        } catch (Throwable $e) {
            Log::channel('single')->warning('Bordereau orphelin non supprimé.', ['erreur' => $e::class]);
        }
    }

    private static function notifier(string $titre, ResultatOperation $resultat): void
    {
        match ($resultat->sms) {
            true => Notification::make()->success()->title($titre)->body('Inscription soldée : l\'auditeur a été prévenu de ses accès par SMS et par e-mail (détail par canal dans le journal d\'activité).')->send(),
            false => Notification::make()->warning()->persistent()->title($titre)
                ->body('Inscription soldée, mais ni le SMS ni l\'e-mail des accès ne sont partis. La scolarité peut les renvoyer depuis la fiche (« Renvoyer le SMS et l\'e-mail »).')->send(),
            null => Notification::make()->success()->title($titre)->send(),
        };
    }
}
