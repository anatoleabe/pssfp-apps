<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CandidatureAccepted;
use App\Events\CandidatureCreated;
use App\Events\CandidatureRefused;
use App\Events\CandidatureSubmitted;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Support\Facades\Log;

/** Canal SMS garanti lorsque l'e-mail facultatif n'est pas renseigné. */
final class SendCandidatureSmsNotifications
{
    public function __construct(private readonly SmsServiceInterface $sms) {}

    public function handleCreated(CandidatureCreated $event): void
    {
        $this->send($event->candidature->phone_e164, sprintf(
            'PSSFP : votre compte candidat est créé. Dossier %s. Conservez votre PIN et complétez photo, pièces puis soumission.',
            $event->candidature->numero_dossier,
        ), $event->candidature->uuid);
    }

    public function handleSubmitted(CandidatureSubmitted $event): void
    {
        $this->send($event->candidature->phone_e164, sprintf(
            'PSSFP : candidature %s soumise avec succès. Consultez votre espace candidat pour le suivi et le récépissé.',
            $event->candidature->numero_dossier,
        ), $event->candidature->uuid);
    }

    public function handleAccepted(CandidatureAccepted $event): void
    {
        $this->send($event->candidature->phone_e164, sprintf(
            'PSSFP : une décision est disponible pour le dossier %s. Connectez-vous à apply.pssfp.org pour la consulter.',
            $event->candidature->numero_dossier,
        ), $event->candidature->uuid);
    }

    public function handleRefused(CandidatureRefused $event): void
    {
        $this->send($event->candidature->phone_e164, sprintf(
            'PSSFP : une décision est disponible pour le dossier %s. Connectez-vous à apply.pssfp.org pour la consulter.',
            $event->candidature->numero_dossier,
        ), $event->candidature->uuid);
    }

    public function subscribe(): array
    {
        return [
            CandidatureCreated::class => 'handleCreated',
            CandidatureSubmitted::class => 'handleSubmitted',
            CandidatureAccepted::class => 'handleAccepted',
            CandidatureRefused::class => 'handleRefused',
        ];
    }

    private function send(string $phone, string $message, string $uuid): void
    {
        try {
            $this->sms->send($phone, $message);
        } catch (\Throwable $exception) {
            // La transaction métier est déjà committée : notifier l'incident,
            // sans transformer une réussite candidat en erreur 500.
            Log::channel('single')->error('Notification SMS candidature échouée.', [
                'candidature_uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
