<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\EnvoiTestService;
use App\Services\Sms\ChecksConnectivity;
use App\Services\Sms\DescribesConfiguration;
use App\Services\Sms\SmsConfigurationSummary;
use App\Services\Sms\SmsDeliveryStatuses;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use App\Support\SecretRedactor;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Réglages applicatifs éditables sans redéploiement.
 *
 * Premier réglage : les adresses recevant une copie cachée de la notification
 * « nouvelle candidature soumise ». Elles étaient jusqu'ici figées dans
 * `MAIL_TO_ADMISSIONS`, donc modifiables uniquement par un accès serveur.
 */
class Parametres extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Paramètres';

    protected static ?string $navigationLabel = 'Paramètres';

    protected static string $view = 'filament.pages.parametres';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'notification_bcc' => AppSettings::candidatureNotificationBcc(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Notification de dépôt de candidature')
                    ->description('À chaque soumission d\'une candidature en ligne, une notification part vers l\'adresse des admissions. Les adresses ci-dessous la reçoivent en copie cachée.')
                    ->schema([
                        TagsInput::make('notification_bcc')
                            ->label('Adresses en copie cachée (Cci)')
                            ->placeholder('prenom.nom@pssfp.net')
                            ->nestedRecursiveRules(['email:rfc', 'max:150'])
                            ->helperText('Saisissez une adresse puis validez avec Entrée. Copie cachée : aucun destinataire ne voit les adresses des autres. Laisser vide pour n\'envoyer qu\'au service des admissions.'),
                    ]),

                Section::make('Envois')
                    ->description('Configuration des passerelles SMS et e-mail. Elle vit dans le fichier .env du serveur et n\'est pas modifiable ici — seuls les deux champs de test ci-dessous sont saisissables.')
                    ->schema([
                        Placeholder::make('sms_simulation')
                            ->label('Attention')
                            // En danger, pas en texte neutre : c'est le cas où
                            // l'on croit envoyer alors que rien ne part, et
                            // c'est le motif d'existence de cet écran.
                            ->content(new HtmlString(
                                '<span class="font-semibold text-danger-600 dark:text-danger-400">'
                                .'Mode simulation : aucun SMS ne part réellement.</span>'
                            ))
                            ->visible(fn (): bool => ($this->descriptionSms()?->envoiReel ?? true) === false),
                        Placeholder::make('sms_fournisseur')
                            ->label('Fournisseur SMS')
                            ->content(fn (): string => $this->descriptionSms()?->libelle ?? 'Fournisseur non documenté'),
                        Placeholder::make('sms_expediteur')
                            ->label('Expéditeur présenté')
                            ->content(fn (): string => $this->descriptionSms()?->expediteur ?? 'Non configuré'),
                        Placeholder::make('sms_jeton')
                            ->label('Jeton API')
                            ->content(fn (): string => ($this->descriptionSms()?->jetonConfigure ?? false)
                                ? 'Configuré'
                                : 'Absent'),
                        Placeholder::make('mail_transport')
                            ->label('Transport e-mail')
                            ->content(fn (): string => (string) config('mail.default')),
                        Placeholder::make('mail_expediteur')
                            ->label('Expéditeur e-mail')
                            ->content(fn (): string => (string) config('mail.from.address')
                                .' ('.(string) config('mail.from.name').')'),
                        // `dehydrated(false)` exclut de l'enregistrement mais
                        // PAS de la validation : une règle `email` ici ferait
                        // échouer la sauvegarde des adresses Cci voisines. La
                        // validation se fait donc dans l'action.
                        TextInput::make('test_telephone')
                            ->label('Numéro pour un SMS de test')
                            ->helperText('Format international (+237…) ou numéro local à 9 chiffres.')
                            ->dehydrated(false)
                            ->hintAction(
                                FormAction::make('tester_sms')
                                    ->label('Envoyer un SMS de test')
                                    ->icon('heroicon-o-device-phone-mobile')
                                    ->visible(fn (): bool => $this->peutEnvoyer())
                                    ->action(fn (): null => $this->testerSms()),
                            ),
                        TextInput::make('test_email')
                            ->label('Adresse pour un e-mail de test')
                            ->dehydrated(false)
                            ->hintAction(
                                FormAction::make('tester_email')
                                    ->label('Envoyer un e-mail de test')
                                    ->icon('heroicon-o-envelope')
                                    ->visible(fn (): bool => $this->peutEnvoyer())
                                    ->action(fn (): null => $this->testerEmail()),
                            ),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        AppSettings::set(
            AppSettings::KEY_CANDIDATURE_NOTIFICATION_BCC,
            array_values(array_filter((array) ($state['notification_bcc'] ?? []))),
            auth()->id(),
        );

        activity('parametres')
            ->causedBy(auth()->user())
            ->event('settings_updated')
            ->withProperties(['key' => AppSettings::KEY_CANDIDATURE_NOTIFICATION_BCC])
            ->log('Réglages de notification mis à jour');

        Notification::make()
            ->title('Paramètres enregistrés')
            ->success()
            ->send();
    }

    /**
     * Description de la passerelle active, ou null si elle ne sait pas se
     * décrire. La page doit rester affichable dans ce cas.
     */
    private function descriptionSms(): ?SmsConfigurationSummary
    {
        $passerelle = app(SmsServiceInterface::class);

        return $passerelle instanceof DescribesConfiguration ? $passerelle->decrire() : null;
    }

    /** Envoyer réellement coûte du crédit : droit distinct de la consultation. */
    private function peutEnvoyer(): bool
    {
        return (bool) auth()->user()?->can('candidature.notify');
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('verifier_connexion')
                ->label('Vérifier la connexion')
                ->icon('heroicon-o-signal')
                ->visible(fn (): bool => app(SmsServiceInterface::class) instanceof ChecksConnectivity
                    && $this->peutEnvoyer())
                ->action(function (): void {
                    $passerelle = app(SmsServiceInterface::class);

                    // Re-testé ici et pas seulement dans visible() : la garde
                    // est à vingt lignes de l'appel, et rien n'empêcherait
                    // qu'on élargisse un jour la condition d'affichage.
                    if (! $passerelle instanceof ChecksConnectivity) {
                        return;
                    }

                    $this->consommerQuotaDiagnostic();

                    $rapport = $passerelle->verifierConnexion();

                    if (! $rapport->joignable) {
                        Notification::make()->danger()
                            ->title('Passerelle injoignable')
                            ->body(SecretRedactor::redact($rapport->erreur) ?? 'Motif inconnu.')
                            ->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('Passerelle joignable')
                        ->body('Compte : '.($rapport->compte ?? 'inconnu')
                            .' — solde : '.($rapport->solde ?? 'inconnu'))
                        ->send();
                }),
        ];
    }

    /**
     * Envoi de test SMS, déclenché par le bouton accolé au champ.
     *
     * La validation se fait ici : les règles du TextInput ne s'exécutent qu'à
     * l'enregistrement du formulaire, jamais sur le chemin d'une action.
     */
    private function testerSms(): null
    {
        $donnees = Validator::make(
            ['test_telephone' => trim((string) ($this->data['test_telephone'] ?? ''))],
            ['test_telephone' => ['required', 'string', 'max:20', 'regex:/^(\+[1-9]\d{6,14}|\d{9})$/']],
            ['test_telephone.regex' => 'Saisissez un numéro au format international (+237…) ou un numéro local à 9 chiffres.'],
        );

        if ($donnees->fails()) {
            Notification::make()->warning()
                ->title('Numéro invalide')
                ->body((string) $donnees->errors()->first())
                ->send();

            return null;
        }

        try {
            $resultat = app(EnvoiTestService::class)
                ->envoyerSms((string) $donnees->validated()['test_telephone'], $this->auteur());
        } catch (\Throwable $e) {
            // Message de la passerelle affiché tel quel — c'est toute l'utilité
            // d'un bouton de test — mais expurgé de tout secret : une clé en
            // query se retrouverait sinon dans une exception de transport.
            Notification::make()->danger()
                ->title('Envoi refusé')
                ->body(SecretRedactor::redact($e->getMessage()) ?? 'Motif inconnu.')
                ->send();

            return null;
        }

        $details = 'Expéditeur : '.($resultat->expediteur ?? 'inconnu')
            .' — statut : '.SmsDeliveryStatuses::libelle($resultat->statut)
            .($resultat->cout === null ? '' : ' — coût : '.$resultat->cout);

        // En simulation, rien n'est parti : une notification verte ferait
        // croire l'inverse, exactement le problème que cet écran doit lever.
        if (($this->descriptionSms()?->envoiReel ?? true) === false) {
            Notification::make()->warning()
                ->title('Aucun SMS envoyé — mode simulation')
                ->body('La passerelle active ne fait que journaliser. '.$details)
                ->send();

            return null;
        }

        Notification::make()->success()
            ->title('SMS de test envoyé')
            ->body($details)
            ->send();

        return null;
    }

    /** Envoi de test e-mail, déclenché par le bouton accolé au champ. */
    private function testerEmail(): null
    {
        $donnees = Validator::make(
            ['test_email' => trim((string) ($this->data['test_email'] ?? ''))],
            ['test_email' => ['required', 'email:rfc', 'max:150']],
        );

        if ($donnees->fails()) {
            Notification::make()->warning()
                ->title('Adresse invalide')
                ->body((string) $donnees->errors()->first())
                ->send();

            return null;
        }

        try {
            app(EnvoiTestService::class)
                ->envoyerEmail((string) $donnees->validated()['test_email'], $this->auteur());
        } catch (\Throwable $e) {
            Notification::make()->danger()
                ->title('Envoi refusé')
                ->body(SecretRedactor::redact($e->getMessage()) ?? 'Motif inconnu.')
                ->send();

            return null;
        }

        Notification::make()->success()->title('E-mail de test envoyé')->send();

        return null;
    }

    /**
     * Auteur de l'action.
     *
     * `canAccess()` garantit un utilisateur authentifié, mais le service attend
     * un `User` non nullable : mieux vaut une exception explicite qu'un
     * TypeError si cette garantie change un jour.
     */
    private function auteur(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Action de diagnostic sans utilisateur authentifié.');
        }

        return $user;
    }

    /**
     * Le contrôle de connexion sort un appel HTTP synchrone de 20 s : il tombe
     * sous le même quota que les envois de test pour qu'une session ne puisse
     * pas marteler la passerelle depuis l'IP de production.
     */
    private function consommerQuotaDiagnostic(): void
    {
        $cle = 'diagnostic-envois:'.$this->auteur()->id;

        if (RateLimiter::tooManyAttempts($cle, 5)) {
            Notification::make()->warning()
                ->title('Trop de vérifications')
                ->body('Patientez une minute avant de réessayer.')
                ->send();

            return;
        }

        RateLimiter::hit($cle, 60);
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Enregistrer')
                ->submit('save'),
        ];
    }
}
