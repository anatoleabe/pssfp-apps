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
            'PSSFP : votre compte candidat est cree. Dossier %s. Conservez votre PIN, completez photo et pieces puis soumettez. Infos 677 25 72 72',
            $event->candidature->numero_dossier,
        ), $event->candidature->uuid);
    }

    public function handleSubmitted(CandidatureSubmitted $event): void
    {
        $this->send($event->candidature->phone_e164, sprintf(
            'PSSFP : candidature %s soumise avec succes. Consultez votre espace candidat pour le suivi et le recepisse. Infos 677 25 72 72',
            $event->candidature->numero_dossier,
        ), $event->candidature->uuid);
    }

    public function handleAccepted(CandidatureAccepted $event): void
    {
        $this->send($event->candidature->phone_e164, sprintf(
            'PSSFP : une decision est disponible pour le dossier %s. Connectez-vous a apply.pssfp.org pour la consulter. Infos 677 25 72 72',
            $event->candidature->numero_dossier,
        ), $event->candidature->uuid);
    }

    public function handleRefused(CandidatureRefused $event): void
    {
        $this->send($event->candidature->phone_e164, sprintf(
            'PSSFP : une decision est disponible pour le dossier %s. Connectez-vous a apply.pssfp.org pour la consulter. Infos 677 25 72 72',
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
