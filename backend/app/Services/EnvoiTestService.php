<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\EnvoiTestMail;
use App\Models\User;
use App\Services\Sms\ReportsSmsDelivery;
use App\Services\Sms\SmsSendResult;
use App\Services\Sms\SmsServiceInterface;
use App\Support\PhoneMasker;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Envois de vérification déclenchés depuis l'administration.
 *
 * Volontairement distinct de NotificationCandidatsService : un test ne vise
 * aucun candidat, n'écrit pas au journal des envois — qui sert de preuve —
 * et ne doit jamais emprunter un gabarit de notification candidat.
 */
final class EnvoiTestService
{
    /** Envois de test autorisés par utilisateur et par minute, tous canaux confondus. */
    private const QUOTA_PAR_MINUTE = 5;

    public function __construct(
        private readonly SmsServiceInterface $sms,
        private readonly NotificationCandidatsService $notifications,
    ) {}

    public function envoyerSms(string $destinataire, User $auteur): SmsSendResult
    {
        $this->consommerQuota($auteur);

        $numero = $this->normaliser($destinataire);

        if (! $this->notifications->smsDesservi($numero)) {
            throw new RuntimeException(
                'Ce numéro n\'est pas dans les indicatifs desservis — envoi refusé avant appel à la passerelle.'
            );
        }

        $message = 'PSSFP : test de configuration SMS. Aucune action requise.';

        if ($this->sms instanceof ReportsSmsDelivery) {
            $resultat = $this->sms->sendAndReport($numero, $message);
        } else {
            $this->sms->send($numero, $message);
            $resultat = new SmsSendResult(expediteur: null, codeFournisseur: null);
        }

        $this->tracer($auteur, 'sms', PhoneMasker::mask($numero));

        return $resultat;
    }

    public function envoyerEmail(string $destinataire, User $auteur): void
    {
        $this->consommerQuota($auteur);

        Mail::to($destinataire)->send(new EnvoiTestMail);

        $this->tracer($auteur, 'email', $destinataire);
    }

    /**
     * Normalise un numéro local camerounais en E.164.
     *
     * Un numéro déjà en E.164 passe tel quel ; neuf chiffres sont préfixés
     * +237. Tout le reste est rendu inchangé et sera refusé par le contrôle
     * des indicatifs desservis — mieux vaut un refus explicite qu'un numéro
     * deviné.
     */
    private function normaliser(string $saisie): string
    {
        $saisie = preg_replace('/\s+/', '', trim($saisie)) ?? '';

        if (preg_match('/^\+[1-9]\d{6,14}$/', $saisie) === 1) {
            return $saisie;
        }

        if (preg_match('/^\d{9}$/', $saisie) === 1) {
            return '+237'.$saisie;
        }

        return $saisie;
    }

    private function consommerQuota(User $auteur): void
    {
        $cle = 'envoi-test:'.$auteur->id;

        if (RateLimiter::tooManyAttempts($cle, self::QUOTA_PAR_MINUTE)) {
            throw new RuntimeException(
                'Trop d\'envois de test — patientez une minute avant de réessayer.'
            );
        }

        RateLimiter::hit($cle, 60);
    }

    private function tracer(User $auteur, string $canal, string $destinataire): void
    {
        activity('envois')
            ->causedBy($auteur)
            ->withProperties([
                'canal' => $canal,
                // Masqué : cette trace est consultable par tout administrateur.
                'destinataire' => $destinataire,
            ])
            ->event('envoi_test')
            ->log('Envoi de test déclenché depuis l\'administration');
    }
}
