<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mail de vérification déclenché depuis l'administration.
 *
 * Volontairement NON queued, contrairement aux e-mails candidats : un test
 * doit dire tout de suite si la configuration fonctionne. Passer par la file
 * reporterait l'échec dans les logs du worker, là où l'administrateur ne le
 * verrait pas.
 */
final class EnvoiTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Test] Vérification de la configuration e-mail — PSSFP',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.envoi-test');
    }
}
