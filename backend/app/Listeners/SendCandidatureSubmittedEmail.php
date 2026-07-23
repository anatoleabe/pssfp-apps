<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CandidatureSubmitted;
use App\Mail\CandidatureSubmittedAdminMail;
use App\Mail\CandidatureSubmittedMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Listener déclenché après la soumission d'une candidature (LOT A).
 *
 * Deux messages distincts sont envoyés : une confirmation au candidat et une
 * notification de traitement à l'administration. L'adresse candidat est
 * obligatoire à la soumission ; le garde-fou ci-dessous protège néanmoins les
 * anciens dossiers créés avant cette règle.
 */
final class SendCandidatureSubmittedEmail
{
    public function handle(CandidatureSubmitted $event): void
    {
        $email = $event->candidature->email;
        if ($email === null || $email === '') {
            Log::channel('single')->info('CandidatureSubmitted sans email — pas d\'envoi de récépissé.', [
                'candidature_uuid' => $event->candidature->uuid,
            ]);
        } else {
            Mail::to($email)->queue(new CandidatureSubmittedMail(
                candidature: $event->candidature,
            ));
        }

        Mail::to(config('mail.admissions_recipient'))->queue(new CandidatureSubmittedAdminMail(
            candidature: $event->candidature,
        ));
    }
}
