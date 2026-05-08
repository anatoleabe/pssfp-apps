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
 * Email de notification de refus envoyé au candidat.
 *
 * Queued (Redis) avec retry 3 backoff exponentiel.
 * Le motif est obligatoirement transmis et figure dans le corps de l'email.
 */
final class CandidatureRefusedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 480];
    }

    public function __construct(
        public readonly Candidature $candidature,
        public readonly string $motif,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.candidature.refused.subject', [
                'numero' => $this->candidature->numero_dossier,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.candidature.refused',
            with: [
                'candidature' => $this->candidature,
                'motif' => $this->motif,
            ],
        );
    }
}
