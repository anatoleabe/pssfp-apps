<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\AppSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
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
