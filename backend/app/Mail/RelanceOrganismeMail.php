<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Relance d'un organisme qui a pris en charge des auditeurs (spec module 4, lot A2 §9.8).
 * Envoyée sur clic d'un agent seulement, jamais par une tâche planifiée.
 */
final class RelanceOrganismeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param  list<array{matricule: string, nom: string, reste: string}>  $lignes  auditeurs couverts qui ont un reste */
    public function __construct(
        public readonly string $organisme,
        public readonly string $lettre,
        public readonly string $portee,
        public readonly string $annee,
        public readonly string $echeance,
        public readonly array $lignes,
        public readonly string $total,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[PSSFP] Prise en charge de vos agents : règlement attendu — '.Str::limit($this->organisme, 80));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.scolarite.relance-organisme');
    }
}
