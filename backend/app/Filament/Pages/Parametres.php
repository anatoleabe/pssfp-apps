<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\EnvoiTestService;
use App\Services\Sms\ChecksConnectivity;
use App\Services\Sms\DescribesConfiguration;
use App\Services\Sms\SmsConfigurationSummary;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

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
                    ->description('Configuration des passerelles SMS et e-mail. Ces valeurs vivent dans le fichier .env du serveur et ne sont pas modifiables ici.')
                    ->schema([
                        Placeholder::make('sms_simulation')
                            ->label('Attention')
                            ->content('Mode simulation : aucun SMS ne part réellement.')
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
                        TextInput::make('test_telephone')
                            ->label('Numéro pour un SMS de test')
                            ->helperText('Format international (+237…) ou numéro local à 9 chiffres.')
                            ->tel()
                            ->dehydrated(false),
                        TextInput::make('test_email')
                            ->label('Adresse pour un e-mail de test')
                            ->email()
                            ->dehydrated(false),
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
                    $rapport = app(SmsServiceInterface::class)->verifierConnexion();

                    if (! $rapport->joignable) {
                        Notification::make()->danger()
                            ->title('Passerelle injoignable')
                            ->body($rapport->erreur ?? 'Motif inconnu.')
                            ->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('Passerelle joignable')
                        ->body('Compte : '.($rapport->compte ?? 'inconnu')
                            .' — solde : '.($rapport->solde ?? 'inconnu'))
                        ->send();
                }),

            Action::make('tester_sms')
                ->label('Envoyer un SMS de test')
                ->icon('heroicon-o-device-phone-mobile')
                ->visible(fn (): bool => $this->peutEnvoyer())
                ->action(function (): void {
                    $destinataire = trim((string) ($this->data['test_telephone'] ?? ''));

                    if ($destinataire === '') {
                        Notification::make()->warning()->title('Renseignez un numéro')->send();

                        return;
                    }

                    try {
                        $resultat = app(EnvoiTestService::class)
                            ->envoyerSms($destinataire, auth()->user());
                    } catch (\Throwable $e) {
                        // Message de la passerelle affiché tel quel : c'est
                        // toute l'utilité d'un bouton de test.
                        Notification::make()->danger()
                            ->title('Envoi refusé')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('SMS de test envoyé')
                        ->body('Expéditeur : '.($resultat->expediteur ?? 'inconnu')
                            .($resultat->cout === null ? '' : ' — coût : '.$resultat->cout))
                        ->send();
                }),

            Action::make('tester_email')
                ->label('Envoyer un e-mail de test')
                ->icon('heroicon-o-envelope')
                ->visible(fn (): bool => $this->peutEnvoyer())
                ->action(function (): void {
                    $destinataire = trim((string) ($this->data['test_email'] ?? ''));

                    if ($destinataire === '') {
                        Notification::make()->warning()->title('Renseignez une adresse')->send();

                        return;
                    }

                    try {
                        app(EnvoiTestService::class)->envoyerEmail($destinataire, auth()->user());
                    } catch (\Throwable $e) {
                        Notification::make()->danger()
                            ->title('Envoi refusé')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('E-mail de test envoyé')->send();
                }),
        ];
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
