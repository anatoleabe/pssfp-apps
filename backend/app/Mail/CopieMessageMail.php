<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Copie externe d'un message interne (lot 14b-1, D31) : l'objet et le lien, jamais le corps. */
final class CopieMessageMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $objet,
        public readonly string $lien,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[PSSFP] '.Str::limit($this->objet, 120));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.messagerie.copie');
    }
}
