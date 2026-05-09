<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ContactAutoReplyMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{nom:string,email:string,subject?:?string}  $payload
     */
    public function __construct(public readonly array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre message a bien été reçu — PSSFP',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.contact-autoreply',
            with: ['payload' => $this->payload],
        );
    }
}
