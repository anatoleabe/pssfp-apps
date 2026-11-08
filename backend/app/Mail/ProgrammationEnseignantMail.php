<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Lettre de programmation (D22) : les cours confiés à l'enseignant pour une période, à accepter ou décliner. */
final class ProgrammationEnseignantMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param  list<array{cours: string, role: string, heures: string, fenetre: string, modalite: string}>  $lignes */
    public function __construct(
        public readonly string $enseignant,
        public readonly string $periode,
        public readonly string $dates,
        public readonly array $lignes,
        public readonly string $adminUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[PSSFP] Votre programmation des enseignements — '.Str::limit($this->periode, 80));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.lmd.programmation-enseignant');
    }
}
