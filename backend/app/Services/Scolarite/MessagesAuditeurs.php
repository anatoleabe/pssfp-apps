<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\User;
use App\Services\NotificationCandidatsService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Messages de la Scolarité aux auditeurs (spec module 4, lot 2 §5), relance des
 * impayés en tête. Les variables sont propres à chaque auditeur ; l'envoi passe
 * par NotificationCandidatsService, qui le trace dans le journal des messages
 * (et le réaffiche dans l'espace de l'auditeur). Jamais de mot de passe.
 */
final class MessagesAuditeurs
{
    public const PERMISSION = 'scolarite.messages';

    public const MAX = 300;

    public const VARIABLES = ['{prenom}', '{nom}', '{matricule}', '{reste}', '{reste_inscription}', '{prochaine_echeance}'];

    public const MODELE_RELANCE = 'PSSFP : {prenom}, il reste {reste} FCFA à régler pour votre scolarité. Prochaine échéance : {prochaine_echeance}.';

    /** Repères qui pourraient un jour désigner un secret : refusés d'office. */
    private const INTERDITES = ['{mot_de_passe}', '{password}', '{code}', '{pin}'];

    public function __construct(private readonly NotificationCandidatsService $notifications) {}

    public static function rendre(string $gabarit, Auditeur $auditeur): string
    {
        $c = $auditeur->candidature;
        $s = $auditeur->inscriptionCourante?->situation();

        return strtr($gabarit, [
            '{prenom}' => (string) $c?->prenom,
            '{nom}' => (string) $c?->nom,
            '{matricule}' => (string) $c?->matricule,
            '{reste}' => self::nombre($s?->reste ?? 0),
            '{reste_inscription}' => self::nombre($s?->resteInscription() ?? 0),
            '{prochaine_echeance}' => self::prochaineEcheance($auditeur),
        ]);
    }

    /**
     * @param  Collection<int, Auditeur>  $auditeurs
     * @return array{total: int, sms_par_message: int, exemple: string}
     */
    public function apercu(Collection $auditeurs, string $canal, string $corps): array
    {
        $premier = $auditeurs->first();
        $exemple = $premier instanceof Auditeur ? self::rendre($corps, $premier) : $corps;
        $dossiers = $auditeurs->map(fn (Auditeur $a) => $a->candidature)->filter()->values();
        $base = $this->notifications->apercu($dossiers, $canal, $exemple);

        return ['total' => $auditeurs->count(), 'sms_par_message' => (int) $base['sms_par_message'], 'exemple' => $exemple];
    }

    /**
     * @param  Collection<int, Auditeur>  $auditeurs
     * @return array{sms_envoyes: int, sms_echecs: int, emails_envoyes: int, emails_echecs: int, ignores: int}
     *
     * @throws InvalidArgumentException message destiné à l'agent
     */
    public function envoyer(Collection $auditeurs, string $canal, string $corps, ?string $sujet, User $auteur): array
    {
        abort_unless($auteur->can(self::PERMISSION), 403);

        if (trim($corps) === '') {
            throw new InvalidArgumentException('Écrivez le message.');
        }
        foreach (self::INTERDITES as $interdite) {
            if (str_contains(mb_strtolower($corps.' '.$sujet), $interdite)) {
                throw new InvalidArgumentException('Ce message ne peut pas contenir '.$interdite.' : aucun code d\'accès ne part par un message groupé.');
            }
        }
        if ($auditeurs->count() > self::MAX) {
            throw new InvalidArgumentException('Au plus '.self::MAX.' destinataires par envoi ('.$auditeurs->count().' sélectionnés) : procédez par lots.');
        }

        $rapport = ['sms_envoyes' => 0, 'sms_echecs' => 0, 'emails_envoyes' => 0, 'emails_echecs' => 0, 'ignores' => 0];
        foreach ($auditeurs as $auditeur) {
            if ($auditeur->candidature === null) {
                $rapport['ignores']++;

                continue;
            }
            $partiel = $this->notifications->envoyer(
                collect([$auditeur->candidature]),
                $canal,
                self::rendre($corps, $auditeur),
                $sujet === null ? null : self::rendre($sujet, $auditeur),
                $auteur,
            );
            foreach ($rapport as $cle => $valeur) {
                $rapport[$cle] = $valeur + (int) ($partiel[$cle] ?? 0);
            }
        }

        activity('scolarite')->causedBy($auteur)->event('messages_auditeurs')
            ->withProperties(['destinataires' => $auditeurs->count(), 'canal' => $canal] + $rapport)
            ->log('Message envoyé à des auditeurs');

        return $rapport;
    }

    /** Premier palier non soldé : l'échéance de l'inscription est un texte, celle des tranches une date. */
    private static function prochaineEcheance(Auditeur $auditeur): string
    {
        $inscription = $auditeur->inscriptionCourante;
        $situation = $inscription?->situation();
        foreach ($situation?->paliers ?? [] as $palier) {
            if ($palier->solde()) {
                continue;
            }

            return $palier->cle === Palier::INSCRIPTION
                ? (string) ($inscription->bareme?->inscription_echeance ?: 'dès que possible')
                : ($palier->echeance?->format('d/m/Y') ?? 'à préciser');
        }

        return 'aucune';
    }

    /** Espaces ASCII : un SMS reste en GSM-7, 160 caractères par segment. */
    private static function nombre(int $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }
}
