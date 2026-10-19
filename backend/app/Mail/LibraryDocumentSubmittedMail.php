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
 * Bibliothèque : un document attend une validation (adressé aux comptes
 * ayant `document.publish`). Ni nom ni adresse du déposant : le lien vers
 * la fiche suffit.
 */
final class LibraryDocumentSubmittedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $documentTitle,
        public readonly string $typeLabel,
        public readonly string $adminUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Bibliothèque PSSFP] Document à valider — '.Str::limit($this->documentTitle, 80));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.library.document-submitted');
    }
}
