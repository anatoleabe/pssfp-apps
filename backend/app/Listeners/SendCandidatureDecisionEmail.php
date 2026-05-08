<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CandidatureAccepted;
use App\Events\CandidatureRefused;
use App\Mail\CandidatureAcceptedMail;
use App\Mail\CandidatureRefusedMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Listener déclenché après les events de décision (PR D F1).
 *
 * Si le candidat n'a pas d'email enregistré, on logge un warning et on
 * ne fait rien — le candidat reçoit la notification au comptoir lors du
 * dépôt physique.
 */
final class SendCandidatureDecisionEmail
{
    public function handleAccepted(CandidatureAccepted $event): void
    {
        $email = $event->candidature->email;
        if ($email === null || $email === '') {
            Log::channel('single')->warning('CandidatureAccepted sans email — pas d\'envoi.', [
                'candidature_uuid' => $event->candidature->uuid,
            ]);

            return;
        }

        Mail::to($email)->queue(new CandidatureAcceptedMail(
            candidature: $event->candidature,
            internalComment: $event->internalComment,
        ));
    }

    public function handleRefused(CandidatureRefused $event): void
    {
        $email = $event->candidature->email;
        if ($email === null || $email === '') {
            Log::channel('single')->warning('CandidatureRefused sans email — pas d\'envoi.', [
                'candidature_uuid' => $event->candidature->uuid,
            ]);

            return;
        }

        Mail::to($email)->queue(new CandidatureRefusedMail(
            candidature: $event->candidature,
            motif: $event->motif,
        ));
    }

    /** Mappage event -> méthode pour l'enregistrement dans le provider. */
    public function subscribe(): array
    {
        return [
            CandidatureAccepted::class => 'handleAccepted',
            CandidatureRefused::class => 'handleRefused',
        ];
    }
}
