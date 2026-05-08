<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPinRequest;
use App\Http\Requests\Auth\LoginCandidatRequest;
use App\Http\Requests\Auth\RegisterCandidatRequest;
use App\Http\Requests\Auth\ResetPinRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\CandidatAuthThrottle;
use App\Services\CandidatUserService;
use App\Services\OtpService;
use App\Services\Sms\SmsServiceInterface;
use App\Support\PhoneMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * Endpoints /v1/auth/candidat/*.
 *
 * Cf. spec module 5 §M4 et ADR-0007. Découpage exact :
 * - register      : crée User candidat + Sanctum token candidat (ttl 7j).
 * - login         : valide phone+PIN, retourne token candidat.
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

    private const CANDIDAT_TOKEN_TTL_DAYS = 7;

    private const PIN_RESET_TOKEN_TTL_MINUTES = 10;

    public function __construct(
        private readonly CandidatUserService $users,
        private readonly OtpService $otp,
        private readonly SmsServiceInterface $sms,
        private readonly CandidatAuthThrottle $throttle,
    ) {}

    public function register(RegisterCandidatRequest $request): JsonResponse
    {
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

    public function forgotPin(ForgotPinRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone_e164');
        $user = $this->users->findByPhone($phone);

        // Anti-énumération : on retourne 202 que le phone existe ou non.
        if ($user !== null) {
            $code = $this->otp->generate(
                $phone,
                'reset_pin',
                ttlMinutes: 10,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );

            $this->sms->send(
                $phone,
                "PSSFP : votre code de réinitialisation est {$code}. Valable 10 minutes."
            );
        }

        return response()->json([
            'message' => 'Si ce numéro est enregistré, un code vient d\'être envoyé par SMS.',
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
