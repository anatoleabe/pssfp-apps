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
 * Email de notification d'acceptation envoyé au candidat.
 *
 * Queued (Redis) avec retry 3 backoff exponentiel — cf. précision F PR D.
 */
final class CandidatureAcceptedMail extends Mailable implements ShouldQueue
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
        public readonly ?string $internalComment = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.candidature.accepted.subject', [
                'numero' => $this->candidature->numero_dossier,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.candidature.accepted',
            with: [
                'candidature' => $this->candidature,
                'internalComment' => $this->internalComment,
            ],
        );
    }
}
