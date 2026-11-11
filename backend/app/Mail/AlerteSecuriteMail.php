<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Lot 14d-4 : alerte au titulaire d'un compte quand sa double authentification change. */
final class AlerteSecuriteMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $nom,
        public readonly string $evenement,
        public readonly string $date,
        public readonly ?string $adresse,
        public readonly string $lien,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[PSSFP] Sécurité de votre compte : '.$this->evenement);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.securite.alerte');
    }
}
