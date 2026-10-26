<?php

declare(strict_types=1);

namespace App\Services\ExportsRetenus;

use App\Mail\NotificationCandidatMail;
use App\Models\Candidature;
use App\Models\User;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Identifiants de la plateforme FOAD (Moodle) d'un auditeur : consultation
 * par l'administration et envoi au candidat par SMS et/ou e-mail.
 *
 * Un auditeur n'a qu'un mot de passe, fixé à l'attribution du matricule et
 * jamais régénéré. Il n'est écrit nulle part ailleurs : ni dans le journal
 * d'activité, ni dans le journal des messages (candidature_relances), ni dans
 * la file d'attente (e-mail envoyé immédiatement).
 */
final class IdentifiantsMoodle
{
    public const CANAL_SMS = 'sms';

    public const CANAL_EMAIL = 'email';

    public const CANAL_LES_DEUX = 'sms_email';

    public function __construct(private readonly SmsServiceInterface $sms) {}

    public static function disponibles(Candidature $c): bool
    {
        return $c->matricule !== null && filled($c->moodle_password);
    }

    public static function identifiant(Candidature $c): string
    {
        return mb_strtolower((string) $c->matricule);
    }

    /** SMS en ASCII et sous 160 caractères : un seul segment facturé. */
    public static function texteSms(Candidature $c): string
    {
        return sprintf(
            'PSSFP : vos acces FOAD %s - identifiant %s - mot de passe %s. Conservez-les.',
            preg_replace('#^https?://#', '', (string) config('pssfp.foad_url')),
            self::identifiant($c),
            (string) $c->moodle_password,
        );
    }

    public static function texteEmail(Candidature $c): string
    {
        return "Voici vos identifiants de la plateforme de formation à distance (FOAD) du PSSFP.\n\n"
            .'Adresse : '.config('pssfp.foad_url')."\n"
            .'Identifiant : '.self::identifiant($c)."\n"
            .'Mot de passe : '.$c->moodle_password."\n\n"
            .'Ces identifiants vous sont propres et ne changent pas : conservez-les et ne les communiquez à personne.';
    }

    /**
     * @return array{sms: ?bool, email: ?bool} true envoyé, false échec, null non tenté
     */
    public function envoyer(Candidature $c, string $canal, User $auteur): array
    {
        if (! in_array($canal, [self::CANAL_SMS, self::CANAL_EMAIL, self::CANAL_LES_DEUX], true)) {
            throw new InvalidArgumentException("Canal inconnu : {$canal}");
        }
        if (! self::disponibles($c)) {
            throw new InvalidArgumentException("Le dossier {$c->numero_dossier} n'a pas encore de matricule.");
        }

        $resultat = ['sms' => null, 'email' => null];

        if ($canal !== self::CANAL_EMAIL && filled($c->phone_e164)) {
            $resultat['sms'] = $this->tenter($c, 'sms', fn () => $this->sms->send((string) $c->phone_e164, self::texteSms($c)));
        }
        if ($canal !== self::CANAL_SMS && filled($c->email)) {
            $resultat['email'] = $this->tenter($c, 'email', fn () => Mail::to((string) $c->email)
                ->sendNow(new NotificationCandidatMail($c, 'PSSFP — Vos identifiants de la plateforme FOAD', self::texteEmail($c))));
        }

        activity('candidatures')->causedBy($auteur)->performedOn($c)
            ->event('moodle_identifiants_envoyes')
            ->withProperties(['canal' => $canal] + $resultat)
            ->log('Identifiants Moodle envoyés au candidat');

        return $resultat;
    }

    public function journaliserConsultation(Candidature $c, User $auteur): void
    {
        activity('candidatures')->causedBy($auteur)->performedOn($c)
            ->event('moodle_identifiants_consultes')
            ->log('Identifiants Moodle consultés');
    }

    private function tenter(Candidature $c, string $canal, callable $envoi): bool
    {
        try {
            $envoi();

            return true;
        } catch (Throwable $e) {
            // Jamais le texte du message dans le journal : il contient le mot de passe.
            Log::channel('single')->error('Envoi des identifiants Moodle échoué.', [
                'dossier' => $c->numero_dossier,
                'canal' => $canal,
                'erreur' => $e::class,
            ]);

            return false;
        }
    }
}
