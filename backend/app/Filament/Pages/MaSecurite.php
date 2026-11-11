<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Models\User;
use App\Services\Audit\AlertesSecurite;
use App\Support\BelongsToModule;
use App\Support\ModuleAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * Lot 14d-4 : la double authentification de son propre compte (activation, confirmation, codes de secours, désactivation).
 */
#[BelongsToModule(ModuleAccess::CORE)]
class MaSecurite extends Page
{
    use ChecksModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $title = 'Ma sécurité';

    protected static ?string $slug = 'ma-securite';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.ma-securite';

    /**
     * Codes de secours à afficher une seule fois (activation ou renouvellement). Protégé, donc hors de l'état Livewire :
     * ils ne transitent pas dans les échanges suivants (revue de sécurité) et disparaissent au rendu suivant.
     */
    protected ?array $codesAffiches = null;

    /** @return list<string>|null */
    public function codesAffiches(): ?array
    {
        return $this->codesAffiches;
    }

    public static function canAccess(): bool
    {
        return static::moduleAllows() && auth()->user() instanceof User;
    }

    public static function obligatoire(): bool
    {
        return (bool) config('pssfp.filament.require_2fa', true);
    }

    public function utilisateur(): User
    {
        /** @var User $u */
        $u = auth()->user();

        return $u;
    }

    public function enConfirmation(): bool
    {
        $u = $this->utilisateur();

        return $u->two_factor_secret !== null && $u->two_factor_confirmed_at === null;
    }

    public function activerAction(): Action
    {
        return Action::make('activer')->label('Activer la double authentification')->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => ! $this->utilisateur()->hasEnabledTwoFactorAuthentication() && ! $this->enConfirmation())
            ->form([$this->champMotDePasse()])
            ->action(function (array $data): void {
                $this->exigerMotDePasse($data);
                app(EnableTwoFactorAuthentication::class)($this->utilisateur(), true);
            });
    }

    public function confirmerAction(): Action
    {
        return Action::make('confirmer')->label('Confirmer avec un code')->icon('heroicon-o-check')
            ->visible(fn (): bool => $this->enConfirmation())
            ->form([TextInput::make('code')->label('Code à 6 chiffres affiché par l\'application')->required()->length(6)
                ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'one-time-code'])])
            ->action(function (array $data): void {
                try {
                    app(ConfirmTwoFactorAuthentication::class)($this->utilisateur(), (string) $data['code']);
                } catch (ValidationException) {
                    Notification::make()->danger()->title('Code invalide')->body('Vérifiez l\'heure de votre téléphone et saisissez le code affiché à l\'instant.')->send();

                    throw new Halt;
                }
                $this->codesAffiches = $this->utilisateur()->refresh()->recoveryCodes();
                $this->journaliser('double_authentification_activee', 'Double authentification activée');
                app(AlertesSecurite::class)->signaler($this->utilisateur(), 'double authentification activée');
                Notification::make()->success()->title('Double authentification activée')->body('Conservez vos codes de secours hors ligne.')->send();
            });
    }

    public function annulerAction(): Action
    {
        return Action::make('annuler')->label('Abandonner')->color('gray')->link()
            ->visible(fn (): bool => $this->enConfirmation())
            ->action(fn () => app(DisableTwoFactorAuthentication::class)($this->utilisateur()));
    }

    public function nouveauxCodesAction(): Action
    {
        return Action::make('nouveauxCodes')->label('Nouveaux codes de secours')->icon('heroicon-o-arrow-path')->color('gray')
            ->visible(fn (): bool => $this->utilisateur()->hasEnabledTwoFactorAuthentication())
            ->modalDescription('Les codes actuels ne fonctionneront plus.')
            ->form([$this->champMotDePasse()])
            ->action(function (array $data): void {
                $this->exigerMotDePasse($data);
                app(GenerateNewRecoveryCodes::class)($this->utilisateur());
                $this->codesAffiches = $this->utilisateur()->refresh()->recoveryCodes();
                $this->journaliser('codes_secours_renouveles', 'Codes de secours renouvelés');
                app(AlertesSecurite::class)->signaler($this->utilisateur(), 'nouveaux codes de secours');
            });
    }

    public function desactiverAction(): Action
    {
        return Action::make('desactiver')->label('Désactiver')->icon('heroicon-o-shield-exclamation')->color('danger')
            ->visible(fn (): bool => $this->utilisateur()->hasEnabledTwoFactorAuthentication() && ! self::obligatoire())
            ->form([$this->champMotDePasse()])
            ->action(function (array $data): void {
                $this->exigerMotDePasse($data);
                app(DisableTwoFactorAuthentication::class)($this->utilisateur());
                $this->codesAffiches = null;
                $this->journaliser('double_authentification_desactivee', 'Double authentification désactivée');
                app(AlertesSecurite::class)->signaler($this->utilisateur(), 'double authentification désactivée');
            });
    }

    private function champMotDePasse(): TextInput
    {
        return TextInput::make('mot_de_passe')->label('Mot de passe actuel')->password()->required()->autocomplete('current-password');
    }

    /** @param  array<string, mixed>  $data */
    private function exigerMotDePasse(array $data): void
    {
        if (! Hash::check((string) ($data['mot_de_passe'] ?? ''), (string) $this->utilisateur()->password)) {
            Notification::make()->danger()->title('Mot de passe incorrect')->send();

            throw new Halt;
        }
    }

    private function journaliser(string $evenement, string $libelle): void
    {
        $u = $this->utilisateur();
        activity('comptes')->causedBy($u)->performedOn($u)->event($evenement)->withProperties(['ip' => request()->ip()])->log($libelle);
    }
}
