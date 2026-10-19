<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Bibliothèque : plusieurs documents soumis en revue d'un coup (action en
 * lot). Un seul message par validateur (plafond d'envoi de l'hébergeur),
 * qui liste les premiers documents et renvoie vers la file « À valider ».
 */
final class LibraryDocumentsSubmittedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** Au-delà, le message renvoie à la file plutôt que de tout lister. */
    public const LISTED_MAX = 20;

    /**
     * @param  non-empty-list<array{title: string, type: string, url: string}>  $documents
     */
    public function __construct(
        public readonly array $documents,
        public readonly string $queueUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Bibliothèque PSSFP] '.count($this->documents).' documents à valider');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.library.documents-submitted', with: [
            'listed' => array_slice($this->documents, 0, self::LISTED_MAX),
            'remaining' => max(0, count($this->documents) - self::LISTED_MAX),
        ]);
    }
}
