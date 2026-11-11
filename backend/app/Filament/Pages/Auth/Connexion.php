<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\Audit\AlertesSecurite;
use App\Services\Audit\VerrouillageComptes;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Throwable;

/**
 * Connexion au back-office.
 *
 * - Lot 14d-2 : un compte verrouillé est refusé AVANT toute vérification du mot de passe, en temps constant (sinon la
 *   réponse, plus rapide avec le bon mot de passe, le laisserait deviner pendant le verrou).
 * - Lot 14d-4 : un compte qui a confirmé sa double authentification doit saisir un code (application ou code de secours)
 *   avant que la session ne s'ouvre. Un code faux compte comme un échec de connexion.
 */
class Connexion extends Login
{
    /** Durée minimale d'un refus, alignée sur l'échec ordinaire (SessionGuard : 200 ms). */
    public const DUREE_MIN_MICROSECONDES = 200_000;

    public const CLE_SESSION = 'connexion_double_authentification';

    public const DUREE_ETAPE_CODE_SECONDES = 300;

    public string $etape = 'identifiants';

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        return $this->etape === 'code' ? $this->verifierCode() : $this->verifierIdentifiants();
    }

    public function recommencer(): void
    {
        session()->forget(self::CLE_SESSION);
        $this->etape = 'identifiants';
        $this->form->fill();
    }

    public function getHeading(): string|Htmlable
    {
        return $this->etape === 'code' ? 'Double authentification' : parent::getHeading();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->etape === 'code'
            ? 'Saisissez le code à 6 chiffres de votre application d\'authentification, ou l\'un de vos codes de secours.'
            : parent::getSubheading();
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getAuthenticateFormAction()->label(fn (): string => $this->etape === 'code' ? 'Valider le code' : __('filament-panels::pages/auth/login.form.actions.authenticate.label')),
            Action::make('recommencer')->label('Changer de compte')->link()->color('gray')
                ->visible(fn (): bool => $this->etape === 'code')->action(fn () => $this->recommencer()),
        ];
    }

    /** @return array<int|string, string|Form> */
    protected function getForms(): array
    {
        $identifiants = fn (): bool => $this->etape !== 'code';

        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent()->visible($identifiants),
                        $this->getPasswordFormComponent()->visible($identifiants),
                        // Pas de « se souvenir de moi » : le cookie de rappel (400 jours) rouvrirait la session sans code.
                        TextInput::make('code')->label('Code')->required()->autocomplete('one-time-code')->autofocus()
                            ->maxLength(32)->extraInputAttributes(['inputmode' => 'numeric'])
                            ->visible(fn (): bool => $this->etape === 'code'),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    private function verifierIdentifiants(): ?LoginResponse
    {
        $data = $this->form->getState();
        $email = (string) ($data['email'] ?? '');
        $compte = $email === '' ? null : User::query()->where('email', $email)->first();

        if ($compte !== null && VerrouillageComptes::estVerrouille($compte)) {
            (new Timebox)->call(fn () => $this->journaliser($compte, 'connexion_refusee_verrouille', 'Connexion refusée : compte verrouillé (mot de passe non vérifié)'),
                self::DUREE_MIN_MICROSECONDES);
            $this->throwFailureValidationException();
        }

        // Vérification sans ouvrir de session : elle ne s'ouvrira qu'après le code, s'il en faut un.
        $credentials = $this->getCredentialsFromFormData($data);
        $provider = Filament::auth()->getProvider();
        $user = null;
        $valide = (new Timebox)->call(function (Timebox $timebox) use ($provider, $credentials, &$user): bool {
            $user = $provider->retrieveByCredentials($credentials);
            $ok = $user !== null && $provider->validateCredentials($user, $credentials);
            if ($ok) {
                $timebox->returnEarly();
            }

            return $ok;
        }, self::DUREE_MIN_MICROSECONDES);

        if (! $valide || ! $user instanceof User) {
            event(new Failed(Filament::getAuthGuard(), $user, $credentials));
            $this->throwFailureValidationException();
        }
        if ($user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            $this->throwFailureValidationException();
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // Nouvel identifiant de session avant l'étape du code : une session imposée par un tiers ne la récupère pas.
            session()->regenerate();
            session()->put(self::CLE_SESSION, [
                'id' => $user->id, 'remember' => false, 'expire' => now()->addSeconds(self::DUREE_ETAPE_CODE_SECONDES)->getTimestamp(),
            ]);
            $this->etape = 'code';
            $this->form->fill();

            return null;
        }

        return $this->ouvrirSession($user, false);
    }

    private function verifierCode(): ?LoginResponse
    {
        $etat = session()->get(self::CLE_SESSION);
        $user = is_array($etat) && ($etat['expire'] ?? 0) >= now()->getTimestamp() ? User::query()->find($etat['id'] ?? 0) : null;
        if ($user === null || VerrouillageComptes::estVerrouille($user) || ! $user->hasEnabledTwoFactorAuthentication()) {
            $this->recommencer();

            throw ValidationException::withMessages(['data.email' => 'Délai dépassé : saisissez à nouveau vos identifiants.']);
        }

        $code = preg_replace('/\s+/', '', (string) ($this->form->getState()['code'] ?? '')) ?? '';
        // Un code à la fois par compte : des requêtes simultanées ne testent pas plusieurs codes avant que le verrou ne tombe.
        $resultat = Cache::lock('connexion-code:'.$user->id, 10)->block(5, function () use ($user, $code): string {
            if (VerrouillageComptes::estVerrouille($user->refresh())) {
                return 'verrouille';
            }
            if ($this->codeApplication($user, $code)) {
                return 'application';
            }
            if ($this->codeSecours($user, $code)) {
                return 'secours';
            }
            $this->journaliser($user, 'code_2fa_invalide', 'Code de double authentification invalide');
            app(VerrouillageComptes::class)->echec($user);

            return 'invalide';
        });
        if ($resultat === 'application') {
            return $this->ouvrirSession($user, false);
        }
        if ($resultat === 'secours') {
            $this->journaliser($user, 'code_secours_utilise', 'Connexion avec un code de secours');
            app(AlertesSecurite::class)->signaler($user, 'connexion avec un code de secours');

            return $this->ouvrirSession($user, false);
        }
        if (VerrouillageComptes::estVerrouille($user->refresh())) {
            $this->recommencer();
            $this->throwFailureValidationException();
        }

        throw ValidationException::withMessages(['data.code' => 'Code invalide.']);
    }

    private function codeApplication(User $user, string $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        try {
            return app(TwoFactorAuthenticationProvider::class)->verify(decrypt($user->two_factor_secret), $code);
        } catch (Throwable) {
            return false;
        }
    }

    private function codeSecours(User $user, string $code): bool
    {
        if ($code === '') {
            return false;
        }
        try {
            foreach ($user->recoveryCodes() as $secours) {
                if (hash_equals((string) $secours, $code)) {
                    $user->replaceRecoveryCode($secours);

                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function ouvrirSession(User $user, bool $remember): LoginResponse
    {
        session()->forget(self::CLE_SESSION);
        Filament::auth()->login($user, $remember);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    private function journaliser(User $compte, string $evenement, string $libelle): void
    {
        activity(VerrouillageComptes::JOURNAL)->performedOn($compte)->event($evenement)
            ->withProperties(['ip' => request()->ip(), 'navigateur' => Str::limit((string) request()->userAgent(), 180, '')])->log($libelle);
    }
}
