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

/** Notification interne, distincte de la confirmation adressée au candidat. */
final class CandidatureSubmittedAdminMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 480];
    }

    public function __construct(public readonly Candidature $candidature) {}

    public function envelope(): Envelope
    {
        $replyTo = $this->candidature->email ? [$this->candidature->email] : [];

        return new Envelope(
            subject: "[PSSFP] Nouvelle candidature — {$this->candidature->numero_dossier}",
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.candidature.submitted-admin',
            with: [
                'candidature' => $this->candidature,
                'adminUrl' => config('pssfp.admin_candidature_url'),
                'statutLabel' => config('statuts_actuels.'.$this->candidature->statut_actuel)
                    ?? (string) $this->candidature->statut_actuel,
            ],
        );
    }
}
