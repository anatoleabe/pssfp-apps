<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ContactMessageMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{nom:string,email:string,phone?:?string,organisation?:?string,subject?:?string,message:string,ip:?string}  $payload
     */
    public function __construct(public readonly array $payload) {}

    public function envelope(): Envelope
    {
        $subject = $this->payload['subject'] ?? null;

        return new Envelope(
            subject: '[Contact pssfp.net] '.($subject !== null && $subject !== '' ? $subject : 'Nouveau message'),
            replyTo: [$this->payload['email']],
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.contact-message',
            with: ['payload' => $this->payload],
        );
    }
}
