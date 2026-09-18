<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendThrottledMail;
use App\Mail\NotificationCandidatMail;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\User;
use App\Services\Sms\DescribesConfiguration;
use App\Services\Sms\ReportsSmsDelivery;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Sms\TraceEnvoi;
use App\Support\PhoneMasker;
use App\Support\SecretRedactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envoi groupé d'un message rédigé à la main, depuis l'admin, à une sélection
 * de candidats.
 *
 * Volontairement distinct de RelanceCandidatureService : celui-ci automatise
 * un message figé selon une cause détectée, celui-là exécute une intention
 * humaine. Les règles diffèrent sur un point essentiel — l'anti-doublon ne
 * s'applique pas ici, un agent pouvant légitimement réécrire aux mêmes
 * personnes. La traçabilité prend le relais : chaque envoi enregistre son
 * texte et son auteur.
 *
 * Limite connue et assumée : l'envoi est synchrone. À l'échelle d'une
 * campagne (quelques centaines de dossiers) c'est acceptable et cela permet
 * de rendre un compte rendu immédiat à l'agent. Au-delà, il faudra passer par
 * la file Redis.
 */
final class NotificationCandidatsService
{
    public const CANAL_SMS = 'sms';

    public const CANAL_EMAIL = 'email';

    public const CANAL_LES_DEUX = 'sms_email';

    /** Indicatifs desservis par le compte Echo SMS (cf. campagne du 3 sept. 2026). */
    private const INDICATIFS_SMS_DESSERVIS = ['+237'];

    public function __construct(private readonly SmsServiceInterface $sms) {}

    /**
     * Prépare un compte rendu AVANT envoi : combien de destinataires, quel
     * coût, qui risque de ne pas être joignable. L'agent décide sur ces
     * chiffres, pas sur une intuition.
     *
     * @param  Collection<int, Candidature>  $candidatures
     * @return array{total: int, avec_sms: int, avec_email: int, sms_hors_couverture: int, sans_sms: int, sans_email: int, sms_par_message: int, cout_sms: int, apercu: string}
     */
    public function apercu(Collection $candidatures, string $canal, string $corps): array
    {
        $avecSms = 0;
        $avecEmail = 0;
        $horsCouverture = 0;

        foreach ($candidatures as $candidature) {
            $numero = $this->numeroDeContact($candidature);
            if ($numero !== null) {
                $avecSms++;
                if (! $this->smsDesservi($numero)) {
                    $horsCouverture++;
                }
            }
            if ($this->emailDeContact($candidature) !== null) {
                $avecEmail++;
            }
        }

        $premiere = $candidatures->first();
        $apercu = $premiere instanceof Candidature
            ? $this->rendre($corps, $premiere)
            : $corps;

        // Longueur mesurée sur un message réel, variables remplacées : un
        // gabarit court peut dépasser 160 caractères une fois le nom inséré.
        $smsParMessage = (int) max(1, ceil(mb_strlen($apercu) / 160));
        $envoisSms = $this->canalInclutSms($canal) ? $avecSms : 0;

        return [
            'total' => $candidatures->count(),
            'avec_sms' => $avecSms,
            'avec_email' => $avecEmail,
            'sms_hors_couverture' => $horsCouverture,
            'sans_sms' => $candidatures->count() - $avecSms,
            'sans_email' => $candidatures->count() - $avecEmail,
            'sms_par_message' => $smsParMessage,
            'cout_sms' => $envoisSms * $smsParMessage,
            'apercu' => $apercu,
        ];
    }

    /**
     * @param  Collection<int, Candidature>  $candidatures
     * @return array{sms_envoyes: int, sms_echecs: int, emails_envoyes: int, emails_echecs: int, ignores: int}
     */
    public function envoyer(
        Collection $candidatures,
        string $canal,
        string $corps,
        ?string $sujet,
        User $auteur,
    ): array {
        $rapport = [
            'sms_envoyes' => 0,
            'sms_echecs' => 0,
            'emails_envoyes' => 0,
            'emails_echecs' => 0,
            'ignores' => 0,
        ];

        foreach ($candidatures as $candidature) {
            $texte = $this->rendre($corps, $candidature);
            $touche = false;

            if ($this->canalInclutSms($canal)) {
                $numero = $this->numeroDeContact($candidature);
                if ($numero !== null) {
                    $touche = true;
                    $this->envoyerSms($candidature, $numero, $texte, $auteur, $rapport);
                }
            }

            if ($this->canalInclutEmail($canal)) {
                $adresse = $this->emailDeContact($candidature);
                if ($adresse !== null) {
                    $touche = true;
                    $this->envoyerEmail($candidature, $adresse, $texte, $sujet, $auteur, $rapport);
                }
            }

            if (! $touche) {
                $rapport['ignores']++;
            }
        }

        return $rapport;
    }

    /**
     * @param  array<string, int>  $rapport
     */
    private function envoyerSms(
        Candidature $candidature,
        string $numero,
        string $texte,
        User $auteur,
        array &$rapport,
    ): void {
        try {
            // Une passerelle qui sait se décrire remonte le Sender ID
            // réellement présenté et le code fournisseur ; sinon on trace
            // l'envoi sans ces détails plutôt que de deviner.
            if ($this->sms instanceof ReportsSmsDelivery) {
                $trace = TraceEnvoi::depuisResultat($this->sms->sendAndReport($numero, $texte));
            } else {
                $this->sms->send($numero, $texte);
                $trace = TraceEnvoi::sms(null);
            }
            $this->tracer($candidature, CandidatureRelance::CANAL_SMS, CandidatureRelance::STATUT_ENVOYE, $texte, null, $auteur, null, $trace);
            $rapport['sms_envoyes']++;
        } catch (Throwable $e) {
            // En échec, la passerelle n'a rien rendu : on conserve tout de
            // même l'expéditeur configuré, c'est souvent lui la cause
            // (Sender ID expiré ou refusé).
            $this->tracer($candidature, CandidatureRelance::CANAL_SMS, CandidatureRelance::STATUT_ECHEC, $texte, null, $auteur, $e->getMessage(), TraceEnvoi::sms($this->expediteurSmsConfigure()));
            Log::channel('sms')->error('Notification SMS en échec', [
                'dossier' => $candidature->numero_dossier,
                'phone' => PhoneMasker::mask($numero),
                'error' => SecretRedactor::redact($e->getMessage()),
            ]);
            $rapport['sms_echecs']++;
        }
    }

    /**
     * @param  array<string, int>  $rapport
     */
    private function envoyerEmail(
        Candidature $candidature,
        string $adresse,
        string $texte,
        ?string $sujet,
        User $auteur,
        array &$rapport,
    ): void {
        $sujetRendu = $this->rendre($sujet ?? 'PSSFP — information', $candidature);

        // La ligne est créée AVANT l'envoi : le job bridé a besoin de son
        // identifiant pour la basculer en échec si le message ne part jamais.
        // Sans ce lien, le journal continuait d'afficher « envoyé » pour des
        // messages refusés par le serveur SMTP des heures plus tard.
        $ligne = $this->tracer($candidature, CandidatureRelance::CANAL_EMAIL, CandidatureRelance::STATUT_ENVOYE, $texte, $sujetRendu, $auteur, null, TraceEnvoi::email($this->expediteurEmailConfigure()));

        try {
            SendThrottledMail::dispatch(
                [$adresse],
                new NotificationCandidatMail($candidature, $sujetRendu, $texte),
                [],
                $ligne->id,
            );
            $rapport['emails_envoyes']++;
        } catch (Throwable $e) {
            $ligne->update([
                'statut' => CandidatureRelance::STATUT_ECHEC,
                'erreur' => mb_substr((string) SecretRedactor::redact($e->getMessage()), 0, 500),
            ]);
            Log::channel('single')->error('Notification e-mail en échec', [
                'dossier' => $candidature->numero_dossier,
                'error' => SecretRedactor::redact($e->getMessage()),
            ]);
            $rapport['emails_echecs']++;
        }
    }

    private function tracer(
        Candidature $candidature,
        string $canal,
        string $statut,
        string $message,
        ?string $sujet,
        User $auteur,
        ?string $erreur,
        ?TraceEnvoi $trace = null,
    ): CandidatureRelance {
        $ligne = CandidatureRelance::create([
            'candidature_id' => $candidature->id,
            'cause' => CandidatureRelance::CAUSE_MANUELLE,
            'canal' => $canal,
            'statut' => $statut,
            'message' => $message,
            'sujet' => $sujet,
            'envoye_par' => $auteur->id,
            'erreur' => $erreur === null ? null : mb_substr((string) SecretRedactor::redact($erreur), 0, 500),
            'expediteur' => $trace?->expediteur,
            'code_fournisseur' => $trace?->codeFournisseur,
            'message_uid' => $trace?->messageUid,
            'statut_livraison' => $trace?->statutLivraison,
            'cout' => $trace?->cout,
            'sent_at' => now(),
        ]);

        activity('candidatures')
            ->causedBy($auteur)
            ->performedOn($candidature)
            ->withProperties(['canal' => $canal, 'statut' => $statut])
            ->event('candidature_notification_manuelle')
            ->log('Notification manuelle envoyée au candidat');

        return $ligne;
    }

    /** Sender ID (ou numéro) configuré pour la passerelle SMS active. */
    /**
     * Expéditeur de la passerelle active.
     *
     * Lu sur la passerelle elle-même, et non sur une configuration codée en
     * dur : cette méthode ne connaissait qu'Echo SMS, si bien qu'après la
     * bascule TechSoft toute ligne SMS en échec perdait son expéditeur —
     * précisément la colonne qui sert à reconnaître un Sender ID refusé.
     */
    private function expediteurSmsConfigure(): ?string
    {
        return $this->sms instanceof DescribesConfiguration
            ? $this->sms->decrire()->expediteur
            : null;
    }

    private function expediteurEmailConfigure(): ?string
    {
        $adresse = (string) config('mail.from.address', '');

        return $adresse === '' ? null : $adresse;
    }

    /** Remplace les variables du modèle par les données du candidat. */
    public function rendre(string $gabarit, Candidature $candidature): string
    {
        return strtr($gabarit, [
            '{prenom}' => (string) $candidature->prenom,
            '{nom}' => (string) $candidature->nom,
            '{numero_dossier}' => (string) $candidature->numero_dossier,
            '{specialite}' => (string) $candidature->specialite,
            '{date_cloture}' => $candidature->campagne?->closes_at?->format('d/m/Y') ?? '',
            '{url}' => (string) config('notification_candidats.url'),
        ]);
    }

    public function canalInclutSms(string $canal): bool
    {
        return $canal === self::CANAL_SMS || $canal === self::CANAL_LES_DEUX;
    }

    public function canalInclutEmail(string $canal): bool
    {
        return $canal === self::CANAL_EMAIL || $canal === self::CANAL_LES_DEUX;
    }

    /** Un numéro hors des indicatifs desservis échouerait côté passerelle. */
    public function smsDesservi(string $numero): bool
    {
        foreach (self::INDICATIFS_SMS_DESSERVIS as $indicatif) {
            if (str_starts_with($numero, $indicatif)) {
                return true;
            }
        }

        return false;
    }

    private function numeroDeContact(Candidature $candidature): ?string
    {
        $login = trim((string) $candidature->phone_e164);
        if ($login !== '' && preg_match('/^\+[1-9]\d{6,14}$/', $login) === 1) {
            return $login;
        }

        $compose = preg_replace('/\s+/', '',
            trim((string) $candidature->indicatif1).trim((string) $candidature->telephone1)) ?? '';

        return preg_match('/^\+[1-9]\d{6,14}$/', $compose) === 1 ? $compose : null;
    }

    private function emailDeContact(Candidature $candidature): ?string
    {
        $email = trim((string) $candidature->email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }
}
