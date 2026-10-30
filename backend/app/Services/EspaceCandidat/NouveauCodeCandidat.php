<?php

declare(strict_types=1);

namespace App\Services\EspaceCandidat;

use App\Mail\NotificationCandidatMail;
use App\Models\Candidature;
use App\Models\User;
use App\Services\CandidatAuthThrottle;
use App\Services\CandidatUserService;
use App\Services\OtpService;
use App\Services\PinService;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Throwable;

/**
 * Nouveau code de connexion (PIN à 6 chiffres) donné par l'administration à
 * un candidat qui a oublié le sien.
 *
 * Le PIN n'est stocké que haché : l'administration ne peut pas lire l'ancien,
 * elle en tire un nouveau. Il est affiché une seule fois à l'agent et envoyé
 * au candidat par le canal choisi. Il n'est écrit nulle part ailleurs : ni
 * dans le journal d'activité, ni dans les journaux techniques, ni dans la
 * file d'attente (e-mail envoyé immédiatement).
 */
final class NouveauCodeCandidat
{
    public const PERMISSION = 'candidature.espace_candidat';

    public const CANAL_SMS = 'sms';

    public const CANAL_EMAIL = 'email';

    public const CANAL_LES_DEUX = 'sms_email';

    /** L'agent communique lui-même le code (téléphone, guichet). */
    public const CANAL_AUCUN = 'aucun';

    private const TIRAGES_MAX = 50;

    public const MAX_PAR_COMPTE = 5;

    public const MAX_PAR_AGENT = 30;

    public function __construct(
        private readonly CandidatUserService $users,
        private readonly PinService $pins,
        private readonly OtpService $otp,
        private readonly CandidatAuthThrottle $throttle,
        private readonly SmsServiceInterface $sms,
    ) {}

    /**
     * Le dossier est rattaché à un compte de candidat pur. Un compte qui porte
     * aussi un rôle du personnel est exclu : son mot de passe d'administration
     * ne doit jamais devenir un code connu d'un autre agent.
     */
    public static function compteCandidat(Candidature $c): ?User
    {
        $user = $c->user;

        return $user !== null && filled($user->phone_e164) && $user->roles->pluck('name')->all() === ['candidat'] ? $user : null;
    }

    /**
     * @return array{code: string, sms: ?bool, email: ?bool} true envoyé, false échec, null non tenté
     */
    public function generer(Candidature $c, string $canal, User $auteur): array
    {
        if (! in_array($canal, [self::CANAL_SMS, self::CANAL_EMAIL, self::CANAL_LES_DEUX, self::CANAL_AUCUN], true)) {
            throw new InvalidArgumentException("Canal inconnu : {$canal}");
        }
        abort_unless($auteur->can(self::PERMISSION), 403);
        $compte = self::compteCandidat($c)
            ?? throw new InvalidArgumentException("Le dossier {$c->numero_dossier} n'est rattaché à aucun compte candidat.");
        $telephone = (string) $compte->phone_e164;

        // Plafonds : chaque code coûte un SMS et déconnecte le candidat ; une
        // session d'agent détournée ne doit pas pouvoir boucler.
        foreach (['compte:'.$compte->id => self::MAX_PAR_COMPTE, 'agent:'.$auteur->id => self::MAX_PAR_AGENT] as $cle => $max) {
            if (! RateLimiter::attempt('nouveau-code|'.$cle, $max, fn (): bool => true, 3600)) {
                throw new TropDeNouveauxCodes($cle === 'agent:'.$auteur->id);
            }
        }

        $code = $this->tirer($compte);
        $this->users->updatePin($compte, $code);

        // Comme après « PIN oublié » : l'ancien code et toute session ouverte
        // cessent de fonctionner, et le candidat peut se reconnecter aussitôt.
        $compte->tokens()->delete();
        $this->otp->invalidatePending($telephone, 'reset_pin');
        $this->throttle->clearOtpCounters($telephone);
        $this->throttle->clearLoginPhoneLock($telephone);

        $resultat = ['sms' => null, 'email' => null];
        if (in_array($canal, [self::CANAL_SMS, self::CANAL_LES_DEUX], true)) {
            $resultat['sms'] = $this->tenter($c, 'sms', fn () => $this->sms->send($telephone, self::texteSms($code)));
        }
        if (in_array($canal, [self::CANAL_EMAIL, self::CANAL_LES_DEUX], true) && filled($c->email)) {
            $resultat['email'] = $this->tenter($c, 'email', fn () => Mail::to((string) $c->email)
                ->sendNow(new NotificationCandidatMail($c, 'PSSFP — Votre nouveau code de connexion', self::texteEmail($code))));
        }

        activity('candidatures')->causedBy($auteur)->performedOn($c)
            ->event('nouveau_code_connexion')
            ->withProperties(['canal' => $canal] + $resultat)
            ->log('Nouveau code de connexion donné au candidat');

        return ['code' => $code] + $resultat;
    }

    /** SMS en ASCII et sous 160 caractères : un seul segment facturé. */
    public static function texteSms(string $code): string
    {
        return sprintf(
            'PSSFP : votre nouveau code de connexion a %s est %s. Ne le communiquez a personne.',
            preg_replace('#^https?://#', '', (string) config('pssfp.candidature_app_url')),
            $code,
        );
    }

    public static function texteEmail(string $code): string
    {
        return "Le PSSFP vous a attribué un nouveau code de connexion à votre espace candidat.\n\n"
            .'Adresse : '.config('pssfp.candidature_app_url')."\n"
            .'Nouveau code : '.$code."\n\n"
            ."Connectez-vous avec votre numéro de téléphone et ce code. L'ancien code ne fonctionne plus. "
            .'Ne communiquez ce code à personne.';
    }

    /** Tirage uniforme, soumis aux mêmes règles qu'un PIN choisi par le candidat. */
    private function tirer(User $compte): string
    {
        for ($i = 0; $i < self::TIRAGES_MAX; $i++) {
            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
            if ($this->pins->validate($code, (string) $compte->phone_e164, $compte->date_naissance)['ok']) {
                return $code;
            }
        }

        throw new \RuntimeException('Impossible de tirer un code conforme.');
    }

    private function tenter(Candidature $c, string $canal, callable $envoi): bool
    {
        try {
            $envoi();

            return true;
        } catch (Throwable $e) {
            // Jamais le texte du message dans le journal : il contient le code.
            Log::channel('single')->error('Envoi du nouveau code de connexion échoué.', [
                'dossier' => $c->numero_dossier,
                'canal' => $canal,
                'erreur' => $e::class,
            ]);

            return false;
        }
    }
}
