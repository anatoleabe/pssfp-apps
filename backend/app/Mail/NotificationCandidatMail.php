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
 * Message rédigé à la main par un administrateur et envoyé à un candidat
 * depuis le bouton « Notifier les candidats sélectionnés ».
 *
 * Le corps arrive déjà rendu (variables remplacées) : la vue se contente de
 * l'habiller aux couleurs de l'institution. Il est affiché en texte simple et
 * non interprété comme du Markdown ou du HTML — un agent n'a pas à se soucier
 * de mise en forme, et un caractère spécial ne doit pas casser le rendu ni
 * ouvrir une injection.
 *
 * Queued (Redis) avec la même politique de reprise que les autres e-mails
 * candidats.
 */
final class NotificationCandidatMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 480];
    }

    public function __construct(
        public readonly Candidature $candidature,
        public readonly string $sujetMessage,
        public readonly string $corpsMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->sujetMessage,
            replyTo: [config('mail.admissions_recipient')],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.candidature.notification',
            with: [
                'candidature' => $this->candidature,
                'corps' => $this->corpsMessage,
                'supportEmail' => config('mail.admissions_recipient'),
            ],
        );
    }
}
