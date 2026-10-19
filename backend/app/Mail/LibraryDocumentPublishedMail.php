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
 * Bibliothèque : le ou les documents d'un déposant viennent d'être publiés.
 * Une publication en lot produit un seul message par déposant (plafond
 * d'envoi de l'hébergeur).
 */
final class LibraryDocumentPublishedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  non-empty-list<array{title: string, type: string, url: string}>  $documents
     */
    public function __construct(public readonly array $documents) {}

    public function envelope(): Envelope
    {
        $subject = count($this->documents) === 1
            ? 'Document publié — '.Str::limit($this->documents[0]['title'], 80)
            : count($this->documents).' documents publiés';

        return new Envelope(subject: '[Bibliothèque PSSFP] '.$subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.library.document-published');
    }
}
