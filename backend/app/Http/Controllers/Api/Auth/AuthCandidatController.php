<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\OtpCooldownException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPinRequest;
use App\Http\Requests\Auth\LoginCandidatRequest;
use App\Http\Requests\Auth\LoginMatriculeRequest;
use App\Http\Requests\Auth\RegisterCandidatRequest;
use App\Http\Requests\Auth\ResetPinRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\Candidature;
use App\Models\User;
use App\Services\CandidatAuthThrottle;
use App\Services\CandidatUserService;
use App\Services\EspaceCandidat\NouveauCodeCandidat;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\OtpService;
use App\Services\Security\TurnstileVerifier;
use App\Services\Sms\SmsServiceInterface;
use App\Support\PhoneMasker;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Endpoints /v1/auth/candidat/*.
 *
 * Cf. spec module 5 §M4 et ADR-0007. Découpage exact :
 * - register      : crée User candidat + Sanctum token candidat (ttl 7j).
 * - login         : valide phone+PIN, retourne token candidat.
 * - loginMatricule: valide matricule + mot de passe FOAD (auditeurs), même token.
 * - forgotPin     : génère OTP, envoie via SmsService, toujours 202.
 * - verifyOtp     : valide code, retourne token court ability `pin:reset`.
 * - resetPin      : nécessite token court, met à jour PIN, révoque tous les tokens.
 * - logout        : révoque le token courant.
 *
 * Codes statut spécifiques : 401 wrong PIN/OTP, 409 phone existant / OTP réutilisé,
 * 410 OTP expiré, 423 phone lockout, 429 rate limit IP, 422 validation.
 */
final class AuthCandidatController extends Controller
{
    /**
     * Abilities émises au token candidat standard (cf. spec §M4 + arbitrage A).
     *
     * `application:submit` séparée de `application:create` (cf. ajout 4 plan PR C) —
     * permet à un assistant USI futur de créer/mettre à jour un dossier au nom
     * d'un candidat sans pouvoir signer la soumission à sa place.
     */
    private const CANDIDAT_ABILITIES = [
        'profile:read',
        'profile:write',
        'application:create',
        'application:read',
        'application:submit',
    ];

    /** Connexion par identifiants FOAD : consultation de l'espace, sans écriture. */
    private const MATRICULE_ABILITIES = ['profile:read', 'application:read'];

    public const NOM_JETON_MATRICULE = 'candidat-matricule';

    private const CANDIDAT_TOKEN_TTL_DAYS = 7;

    private const PIN_RESET_TOKEN_TTL_MINUTES = 10;

    public function __construct(
        private readonly CandidatUserService $users,
        private readonly OtpService $otp,
        private readonly SmsServiceInterface $sms,
        private readonly CandidatAuthThrottle $throttle,
        private readonly TurnstileVerifier $turnstile,
    ) {}

    public function register(RegisterCandidatRequest $request): JsonResponse
    {
        if (! $this->turnstile->verify($request->input('turnstile_token'), $request->ip())) {
            return $this->turnstileFailed();
        }

        $phone = (string) $request->input('phone_e164');

        if ($this->users->existsForPhone($phone)) {
            return response()->json([
                'message' => 'Ce numéro est déjà enregistré. Connectez-vous.',
            ], 409);
        }

        $user = $this->users->createCandidat($phone, (string) $request->input('pin'), [
            'prenom' => (string) $request->input('prenom'),
            'nom' => (string) $request->input('nom'),
            'phone_country' => (string) $request->input('phone_country'),
            'date_naissance' => (string) $request->input('date_naissance'),
        ]);

        $token = $user->createToken(
            'candidat',
            self::CANDIDAT_ABILITIES,
            now()->addDays(self::CANDIDAT_TOKEN_TTL_DAYS),
        );

        return response()->json([
            'user' => $this->presentUser($user),
            'token' => $token->plainTextToken,
            'abilities' => self::CANDIDAT_ABILITIES,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ], 201);
    }

    public function login(LoginCandidatRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone_e164');
        $pin = (string) $request->input('pin');
        $ip = (string) $request->ip();

        $user = $this->users->findByPhone($phone);

        if ($user === null || ! Hash::check($pin, $user->password)) {
            $this->throttle->recordLoginFailure($phone, $ip);
            $this->logLoginFailure($phone, $ip, $user === null ? 'unknown_phone' : 'wrong_pin');

            // Ne pas révéler si le phone existe ou non (anti-énumération).
            return response()->json([
                'message' => 'Numéro de téléphone ou PIN incorrect.',
            ], 401);
        }

        if (! $user->hasRole('candidat')) {
            // Compte existant mais pas un candidat (admin/editor/etc.) — refuser sur cet endpoint.
            $this->throttle->recordLoginFailure($phone, $ip);
            $this->logLoginFailure($phone, $ip, 'wrong_role');

            return response()->json([
                'message' => 'Numéro de téléphone ou PIN incorrect.',
            ], 401);
        }

        $this->throttle->clearLoginOnSuccess($phone, $ip);

        $token = $user->createToken(
            'candidat',
            self::CANDIDAT_ABILITIES,
            now()->addDays(self::CANDIDAT_TOKEN_TTL_DAYS),
        );

        return response()->json([
            'user' => $this->presentUser($user),
            'token' => $token->plainTextToken,
            'abilities' => self::CANDIDAT_ABILITIES,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Connexion d'un auditeur avec ses identifiants FOAD : matricule et mot de
     * passe Moodle (attribués une fois, jamais régénérés). Mêmes protections
     * que le PIN : compteurs par visiteur et par matricule, message identique
     * que le matricule existe ou non, comparaison en temps constant.
     */
    public function loginMatricule(LoginMatriculeRequest $request): JsonResponse
    {
        $matricule = mb_strtoupper((string) $request->input('identifiant'));
        $motDePasse = (string) $request->input('mot_de_passe');
        $ip = (string) $request->ip();
        $cle = 'matricule:'.$matricule;

        if (($bloque = $this->throttle->checkLogin($cle, $ip)) !== null) {
            return response()->json([
                'message' => $bloque['kind'] === 'phone_locked'
                    ? 'Compte temporairement bloqué après trop d\'échecs. Réessayez plus tard.'
                    : 'Trop de tentatives. Réessayez dans quelques minutes.',
                'kind' => $bloque['kind'],
                'retry_after' => $bloque['retry_after'],
            ], $bloque['kind'] === 'phone_locked' ? 423 : 429, ['Retry-After' => (string) $bloque['retry_after']]);
        }

        $candidature = Candidature::query()->with('user.roles')->where('matricule', $matricule)->first();
        try {
            $compte = $candidature !== null && IdentifiantsMoodle::disponibles($candidature)
                ? NouveauCodeCandidat::compteCandidat($candidature)
                : null;
            $attendu = $compte !== null ? (string) $candidature->moodle_password : Str::random(32);
        } catch (DecryptException) {
            // Valeur illisible (clé d'application changée) : un échec ordinaire, jamais une 500 révélatrice.
            Log::channel('single')->error('Mot de passe FOAD illisible à la connexion.', ['matricule' => $matricule]);
            $compte = null;
            $attendu = Str::random(32);
        }

        // Toujours une comparaison, à longueur fixe : ni l'existence du compte ni
        // la longueur du mot de passe ne se lisent dans le temps de réponse.
        if (! hash_equals(hash('sha256', $attendu), hash('sha256', $motDePasse)) || $compte === null) {
            $this->throttle->recordLoginFailure($cle, $ip);
            activity('auth.candidat')
                ->withProperties(['matricule' => $matricule, 'ip' => $ip, 'reason' => $compte === null ? 'unknown_matricule' : 'wrong_password'])
                ->event('login_failed')
                ->log('Candidate login failed (matricule)');

            return response()->json(['message' => 'Matricule ou mot de passe incorrect.'], 401);
        }

        $this->throttle->clearLoginOnSuccess($cle, $ip);
        // Lecture seule : ce mot de passe est connu de l'administration et ne
        // change jamais. Modifier ou soumettre un dossier exige téléphone + PIN.
        $token = $compte->createToken(self::NOM_JETON_MATRICULE, self::MATRICULE_ABILITIES, now()->addDays(self::CANDIDAT_TOKEN_TTL_DAYS));
        activity('auth.candidat')->causedBy($compte)->performedOn($candidature)
            ->withProperties(['methode' => 'matricule', 'ip' => $ip])
            ->event('login')
            ->log('Connexion à l\'espace par matricule');

        return response()->json([
            'user' => $this->presentUser($compte),
            'token' => $token->plainTextToken,
            'abilities' => self::MATRICULE_ABILITIES,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ]);
    }

    public function forgotPin(ForgotPinRequest $request): JsonResponse
    {
        if (! $this->turnstile->verify($request->input('turnstile_token'), $request->ip())) {
            return $this->turnstileFailed();
        }

        $phone = (string) $request->input('phone_e164');
        $user = $this->users->findByPhone($phone);

        // Anti-énumération : on retourne 202 que le phone existe ou non.
        // Même contrat en cooldown/plafond OTP (un 429 ciblé révélerait que
        // le numéro est enregistré) et en panne SMS (le candidat réessaiera).
        if ($user !== null) {
            try {
                $code = $this->otp->generate(
                    $phone,
                    'reset_pin',
                    ttlMinutes: 10,
                    ip: $request->ip(),
                    userAgent: $request->userAgent(),
                );

                $this->sms->send(
                    $phone,
                    "PSSFP : votre code de reinitialisation est {$code}. Valable 10 minutes."
                );
            } catch (OtpCooldownException $e) {
                Log::channel('single')->notice('OTP forgot-pin throttlé par numéro — 202 silencieux.', [
                    'reason' => $e->reason,
                    'retry_after' => $e->retryAfterSeconds,
                ]);
            } catch (\Throwable $e) {
                Log::channel('single')->error('Envoi SMS forgot-pin échoué — 202 silencieux.', [
                    // Une erreur réseau peut contenir l'URL du fournisseur et sa clé d'API.
                    'error' => SecretRedactor::redact($e->getMessage()),
                ]);
            }
        }

        return response()->json([
            'message' => 'Si ce numéro est enregistré, un code vient d\'être envoyé par SMS.',
            // Délai avant de pouvoir redemander un code : identique pour tous
            // les numéros (anti-énumération), il sert au compte à rebours de
            // l'écran et évite de consommer des SMS par des clics répétés.
            'resend_after' => max(0, (int) config('pssfp.otp.cooldown_seconds', 60)),
        ], 202);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone_e164');
        $code = (string) $request->input('code');

        $throttleResult = $this->throttle->checkOtpVerify($phone);
        if ($throttleResult !== null) {
            return response()->json([
                'message' => 'Trop de tentatives de vérification. Réessayez dans une heure.',
                'retry_after' => $throttleResult['retry_after'],
            ], 429, ['Retry-After' => (string) $throttleResult['retry_after']]);
        }

        $this->throttle->recordOtpAttempt($phone);

        $result = $this->otp->validate($phone, $code, 'reset_pin');

        $status = match ($result) {
            'ok' => 200,
            'expired' => 410,
            'already_used' => 409,
            'exhausted', 'wrong_code', 'not_found' => 401,
            default => 401,
        };

        if ($result !== 'ok') {
            return response()->json([
                'message' => match ($result) {
                    'expired' => 'Le code a expiré. Demandez-en un nouveau.',
                    'already_used' => 'Ce code a déjà été utilisé.',
                    'exhausted' => 'Trop d\'essais sur ce code. Demandez-en un nouveau.',
                    default => 'Code incorrect.',
                },
                'kind' => $result,
            ], $status);
        }

        $user = $this->users->findByPhone($phone);
        if ($user === null) {
            // Cas pathologique : OTP valide mais user disparu (suppression admin).
            return response()->json(['message' => 'Compte introuvable.'], 410);
        }

        $resetToken = $user->createToken(
            'pin-reset',
            ['pin:reset'],
            now()->addMinutes(self::PIN_RESET_TOKEN_TTL_MINUTES),
        );

        return response()->json([
            'pin_reset_token' => $resetToken->plainTextToken,
            'expires_at' => $resetToken->accessToken->expires_at?->toIso8601String(),
        ]);
    }

    public function resetPin(ResetPinRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $newPin = (string) $request->input('pin');

        $this->users->updatePin($user, $newPin);

        // Révocation totale (cf. ajout 3 plan PR B) : si compromis du compte
        // suspecté, aucun token volé ne doit survivre au reset.
        $user->tokens()->delete();

        // Annule tous les OTP non consommés du même phone+purpose (ajout 4).
        $this->otp->invalidatePending($user->phone_e164 ?? '', 'reset_pin');

        // Reset compteurs anti-bruteforce — bonne UX si l'utilisateur revient juste après.
        $this->throttle->clearOtpCounters($user->phone_e164 ?? '');

        return response()->noContent();
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->noContent();
    }

    private function turnstileFailed(): JsonResponse
    {
        $message = 'Vérification anti-robot échouée. Rechargez la page et réessayez.';

        return response()->json([
            'message' => $message,
            'errors' => ['turnstile_token' => [$message]],
        ], 422);
    }

    /** @return array<string, mixed> */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone_e164' => $user->phone_e164,
            'phone_country' => $user->phone_country,
            'roles' => $user->getRoleNames()->all(),
        ];
    }

    private function logLoginFailure(string $phone, string $ip, string $reason): void
    {
        // Activity log anonyme : on n'attache pas le User (causedBy null par défaut)
        // car le candidat n'est pas authentifié au moment de l'échec, et on ne veut
        // pas exposer le user_id potentiel dans les logs si le phone est connu.
        activity('auth.candidat')
            ->withProperties([
                'phone_e164_masked' => PhoneMasker::mask($phone),
                'ip' => $ip,
                'reason' => $reason,
            ])
            ->event('login_failed')
            ->log('Candidate login failed');
    }
}
