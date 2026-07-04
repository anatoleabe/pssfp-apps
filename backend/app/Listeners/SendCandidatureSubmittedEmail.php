<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CandidatureSubmitted;
use App\Mail\CandidatureSubmittedMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Listener déclenché après la soumission d'une candidature (LOT A).
 *
 * L'email est optionnel côté candidat : si aucune adresse n'est enregistrée,
 * on logge un warning sans lever d'exception — le candidat récupère son
 * récépissé directement dans son espace, et les instructions de paiement lui
 * sont rappelées au comptoir. On ne bloque jamais la soumission.
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

            return;
        }

        Mail::to($email)->queue(new CandidatureSubmittedMail(
            candidature: $event->candidature,
        ));
    }
}
