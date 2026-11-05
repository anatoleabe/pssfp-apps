<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\User;
use App\Services\NotificationCandidatsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
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

    /** Envoi synchrone (SMS) dans la requête de l'admin : comme les identifiants, par lots. */
    public const MAX = 100;

    /** Envois par heure et par agent : un double clic ou une boucle ne rejoue pas 100 SMS. */
    public const ENVOIS_PAR_HEURE = 6;

    /** Statuts qui ne reçoivent pas de relance : la formation a pris fin. */
    public const STATUTS_EXCLUS = [Auditeur::STATUT_EXCLU, Auditeur::STATUT_ABANDON];

    public const VARIABLES = ['{prenom}', '{nom}', '{matricule}', '{reste}', '{reste_inscription}', '{prochaine_echeance}'];

    public const MODELE_RELANCE = 'PSSFP : {prenom}, il reste {reste} FCFA à régler pour votre scolarité. Prochaine échéance : {prochaine_echeance}.';

    /** Repères qui pourraient un jour désigner un secret : refusés d'office. */
    private const INTERDITES = ['{mot_de_passe}', '{password}', '{code}', '{pin}'];

    public function __construct(private readonly NotificationCandidatsService $notifications) {}

    public static function rendre(string $gabarit, Auditeur $auditeur): string
    {
        $c = $auditeur->candidature;
        // Une seule fois par auditeur : chaque situation() relit les versements.
        $s = $auditeur->inscriptionCourante?->situation();

        return strtr($gabarit, [
            '{prenom}' => (string) $c?->prenom,
            '{nom}' => (string) $c?->nom,
            '{matricule}' => (string) $c?->matricule,
            '{reste}' => self::nombre($s?->reste ?? 0),
            '{reste_inscription}' => self::nombre($s?->resteInscription() ?? 0),
            '{prochaine_echeance}' => self::prochaineEcheance($auditeur, $s),
        ]);
    }

    /** Dossier retiré, formation achevée par exclusion ou abandon : pas de relance. */
    public static function destinataire(Auditeur $auditeur): bool
    {
        return $auditeur->candidature !== null
            && $auditeur->candidature->withdrawn_at === null
            && ! in_array($auditeur->statut, self::STATUTS_EXCLUS, true);
    }

    /** Précharge ce que rendre() lit, totaux compris, pour toute la sélection. */
    public static function precharger(Collection $auditeurs): Collection
    {
        return \Illuminate\Database\Eloquent\Collection::make($auditeurs->all())->load([
            'candidature.campagne',
            'inscriptionCourante' => fn ($q) => $q->with('bareme')->withSum('versementsValides', 'montant'),
        ]);
    }

    /**
     * @param  Collection<int, Auditeur>  $auditeurs
     * @return array{total: int, destinataires: int, ecartes: int, sms_par_message: int, cout_sms: int, hors_couverture: int, exemple: string, trop: bool}
     */
    public function apercu(Collection $auditeurs, string $canal, string $corps): array
    {
        $retenus = $auditeurs->filter(fn (Auditeur $a): bool => self::destinataire($a))->values();
        // Le texte d'exemple sur un seul auditeur ; les compteurs sur les coordonnées seules.
        $premier = $retenus->first();
        $exemple = $premier instanceof Auditeur ? self::rendre($corps, $premier) : $corps;
        $base = $this->notifications->apercu($retenus->map(fn (Auditeur $a) => $a->candidature)->values(), $canal, $exemple);

        return [
            'total' => $auditeurs->count(),
            'destinataires' => $retenus->count(),
            'ecartes' => $auditeurs->count() - $retenus->count(),
            'sms_par_message' => (int) $base['sms_par_message'],
            'cout_sms' => (int) $base['cout_sms'],
            'hors_couverture' => (int) $base['sms_hors_couverture'],
            'exemple' => $exemple,
            'trop' => $auditeurs->count() > self::MAX,
        ];
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
        $cle = 'messages-auditeurs|'.$auteur->id;
        if (RateLimiter::tooManyAttempts($cle, self::ENVOIS_PAR_HEURE)) {
            throw new InvalidArgumentException('Trop d\'envois en une heure : réessayez dans '.max(1, (int) ceil(RateLimiter::availableIn($cle) / 60)).' min.');
        }
        RateLimiter::hit($cle, 3600);

        // Écrite avant la boucle : un envoi coupé en cours de route reste visible au journal.
        $journal = activity('scolarite')->causedBy($auteur)->event('messages_auditeurs')
            ->withProperties(['destinataires' => $auditeurs->count(), 'canal' => $canal, 'termine' => false])
            ->log('Message envoyé à des auditeurs');

        $rapport = ['sms_envoyes' => 0, 'sms_echecs' => 0, 'emails_envoyes' => 0, 'emails_echecs' => 0, 'ignores' => 0];
        foreach (self::precharger($auditeurs) as $auditeur) {
            if (! self::destinataire($auditeur)) {
                $rapport['ignores']++;

                continue;
            }
            $partiel = $this->notifications->envoyer(
                collect([$auditeur->candidature]),
                $canal,
                self::rendre($corps, $auditeur),
                $sujet === null ? null : self::rendre($sujet, $auditeur),
                $auteur,
                dejaRendu: true,
            );
            foreach ($rapport as $k => $valeur) {
                $rapport[$k] = $valeur + (int) ($partiel[$k] ?? 0);
            }
        }

        $journal->properties = $journal->properties->merge(['termine' => true] + $rapport);
        $journal->save();

        return $rapport;
    }

    /** Premier palier non soldé : l'échéance de l'inscription est un texte, celle des tranches une date. */
    private static function prochaineEcheance(Auditeur $auditeur, ?SituationFinanciere $situation): string
    {
        $inscription = $auditeur->inscriptionCourante;
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
