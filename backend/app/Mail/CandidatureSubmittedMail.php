<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Candidature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email de confirmation de soumission envoyé au candidat.
 *
 * Contient le rappel du numéro de dossier, les instructions de paiement des
 * frais CREMINCAM (cf. spec §8) et un lien vers l'espace candidat pour
 * télécharger le récépissé PDF.
 *
 * Queued (Redis) avec retry 3 backoff exponentiel — même politique que les
 * emails de décision.
 */
final class CandidatureSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** Nombre max de tentatives avant échec définitif. */
    public int $tries = 3;

    /** Backoff en secondes entre tentatives : 30s, 2min, 8min. */
    public function backoff(): array
    {
        return [30, 120, 480];
    }

    public function __construct(
        public readonly Candidature $candidature,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.candidature.submitted.subject', [
                'numero' => $this->candidature->numero_dossier,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.candidature.submitted',
            with: [
                'candidature' => $this->candidature,
                'fraisFcfa' => number_format((float) config('pssfp.frais_dossier_fcfa', 50000), 0, ',', ' '),
                'dossierUrl' => config('pssfp.candidature_app_url').'/dossier/suivi',
            ],
        );
    }
}
