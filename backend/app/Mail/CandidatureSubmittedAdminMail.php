<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Candidature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Notification interne, distincte de la confirmation adressée au candidat. */
final class CandidatureSubmittedAdminMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 480];
    }

    public function __construct(public readonly Candidature $candidature) {}

    public function envelope(): Envelope
    {
        $replyTo = $this->candidature->email ? [$this->candidature->email] : [];

        return new Envelope(
            subject: "[PSSFP] Nouvelle candidature — {$this->candidature->numero_dossier}",
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.candidature.submitted-admin',
            with: [
                'candidature' => $this->candidature,
                'adminUrl' => config('pssfp.admin_candidature_url'),
                'statutLabel' => match ($this->candidature->statut_actuel) {
                    'Etudiant' => 'Étudiant(e)',
                    'Sans-emploi' => 'Sans emploi / en recherche d’emploi',
                    'Fonctionnaire' => 'Fonctionnaire titulaire',
                    'Contractuel-Etat' => 'Agent contractuel de l’État',
                    'Etablissement-public' => 'Agent d’un établissement public',
                    'Entreprise-publique' => 'Salarié(e) d’une entreprise publique',
                    'Prive' => 'Salarié(e) du secteur privé',
                    'Independant' => 'Indépendant(e) / profession libérale',
                    'ONG-International' => 'ONG / organisation internationale',
                    'Autre' => 'Autre situation professionnelle',
                    default => (string) $this->candidature->statut_actuel,
                },
            ],
        );
    }
}
