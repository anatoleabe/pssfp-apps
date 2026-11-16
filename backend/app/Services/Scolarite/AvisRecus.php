<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\NotificationCandidatsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avis à l'auditeur qu'un reçu de versement est disponible dans son espace (spec Scolarité lot 4 §5,
 * décision du 15/11/2026) : un SMS et un e-mail, après la validation de l'émission.
 *
 * Circuit des messages aux auditeurs (NotificationCandidatsService) : chaque envoi est tracé au journal
 * des envois (motif « recu »), réaffiché dans l'espace de l'auditeur, et l'e-mail passe par la file bridée.
 * Rien de secret dans ces textes. Pas d'avis à l'annulation d'un reçu.
 */
final class AvisRecus
{
    public function __construct(private readonly NotificationCandidatsService $notifications) {}

    /**
     * ASCII, un seul segment, sans numéro de téléphone. L'adresse finit le message : un point
     * collé à l'adresse a déjà ouvert sur mobile une origine refusée (500, PR #114).
     */
    public static function texteSms(string $numero, int $montant): string
    {
        return "PSSFP : votre recu {$numero} de {$montant} FCFA est disponible dans votre espace ".self::espace();
    }

    /** Reçu d'un versement corrigé (ADR-0015, R-C10) : il cite le reçu qu'il remplace. ASCII, un segment, l'adresse à la fin. */
    public static function texteSmsRemplacement(string $numero, int $montant, string $ancien): string
    {
        return "PSSFP : votre recu {$numero} de {$montant} FCFA remplace le recu {$ancien}, annule. Il est dans votre espace ".self::espace();
    }

    public static function sujetEmailRemplacement(string $numero, string $ancien): string
    {
        return 'PSSFP — Votre reçu '.$numero.' remplace le reçu '.$ancien;
    }

    public static function texteEmailRemplacement(string $numero, int $montant, string $anneeAcademique, string $ancien): string
    {
        return "Le service financier du PSSFP a corrigé l'un de vos versements : le reçu {$ancien} est annulé et remplacé par le reçu {$numero} de "
            .RecusVersements::fcfa($montant)." (frais de scolarité, année académique {$anneeAcademique}).\n\n"
            .'Téléchargez-le dans votre espace, rubrique « Mes versements » : '.self::espace();
    }

    /**
     * Annulation d'un versement par le service financier (ADR-0015, R-C10, décision Q5) : avec le numéro du reçu annulé,
     * ou, sans reçu, la date et le montant du versement. ASCII, un segment, l'adresse à la fin.
     */
    public static function texteSmsAnnulation(?string $numero, int $montant, string $date): string
    {
        $objet = $numero !== null ? "votre recu {$numero} de {$montant} FCFA" : "votre versement du {$date} de {$montant} FCFA";

        return "PSSFP : {$objet} a ete annule par le service financier. Votre situation est dans votre espace ".self::espace();
    }

    public static function sujetEmailAnnulation(?string $numero): string
    {
        return $numero !== null ? 'PSSFP — Reçu '.$numero.' annulé' : 'PSSFP — Versement annulé';
    }

    public static function texteEmailAnnulation(?string $numero, int $montant, string $date, string $anneeAcademique): string
    {
        $objet = $numero !== null ? "le reçu {$numero} de votre versement de ".RecusVersements::fcfa($montant)." du {$date}"
            : 'votre versement de '.RecusVersements::fcfa($montant)." du {$date}";

        return "Le service financier du PSSFP a annulé {$objet} (frais de scolarité, année académique {$anneeAcademique}). "
            ."Ce versement ne compte plus dans votre situation. Pour toute question, adressez-vous au service financier.\n\n"
            .'Votre situation est dans votre espace, rubrique « Mes versements » : '.self::espace();
    }

    public static function sujetEmail(string $numero): string
    {
        return 'PSSFP — Votre reçu '.$numero;
    }

    public static function texteEmail(string $numero, int $montant, string $anneeAcademique): string
    {
        return "Le service financier du PSSFP a émis le reçu {$numero} pour votre versement de ".RecusVersements::fcfa($montant)
            ." (frais de scolarité, année académique {$anneeAcademique}).\n\n"
            .'Téléchargez-le dans votre espace, rubrique « Mes versements » : '.self::espace();
    }

    /**
     * À appeler dans la transaction de l'émission : l'avis part après sa validation, et jamais
     * si elle est annulée, y compris par la transaction d'un appelant.
     */
    public function apresEmission(RecuVersement $recu, Candidature $dossier, User $auteur): void
    {
        DB::afterCommit(fn () => $this->prevenir($recu, $dossier, $auteur));
    }

    /** SMS puis e-mail. Ne lève jamais : un incident d'envoi ne défait pas le reçu. */
    public function prevenir(RecuVersement $recu, Candidature $dossier, ?User $auteur): void
    {
        try {
            // Reçu d'une correction pour le même auditeur : il cite le reçu remplacé ; jamais celui d'un autre auditeur.
            $recu->loadMissing(['versement:id,auditeur_id', 'remplace.versement:id,auditeur_id']);
            $ancien = $recu->memeAuditeur($recu->remplace) ? $recu->remplace->numero : null;
            [$texteSms, $sujet, $texteEmail, $cause] = $ancien === null
                ? [self::texteSms($recu->numero, $recu->montant), self::sujetEmail($recu->numero),
                    self::texteEmail($recu->numero, $recu->montant, (string) $recu->annee_academique), CandidatureRelance::CAUSE_RECU]
                : [self::texteSmsRemplacement($recu->numero, $recu->montant, $ancien), self::sujetEmailRemplacement($recu->numero, $ancien),
                    self::texteEmailRemplacement($recu->numero, $recu->montant, (string) $recu->annee_academique, $ancien), CandidatureRelance::CAUSE_CORRECTION];
            $sms = $this->envoyer($dossier, NotificationCandidatsService::CANAL_SMS, $texteSms, null, $auteur, $cause);
            $email = $this->envoyer($dossier, NotificationCandidatsService::CANAL_EMAIL, $texteEmail, $sujet, $auteur, $cause);
            $bilan = collect($sms)->map(fn (int $n, string $cle): int => $n + (int) ($email[$cle] ?? 0))->all();

            activity('scolarite')->causedBy($auteur)->performedOn($recu)->event('recu_avis')
                ->withProperties(['numero' => $recu->numero] + $bilan)
                ->log($bilan['sms_envoyes'] + $bilan['emails_envoyes'] > 0
                    ? 'Reçu émis : auditeur prévenu par SMS et par e-mail'
                    : 'Reçu émis : avis à l\'auditeur non envoyé');
        } catch (Throwable $e) {
            // Jamais le texte ni les coordonnées : le numéro du reçu suffit pour retrouver l'envoi.
            Log::channel('single')->error('Avis du reçu à l\'auditeur interrompu.', ['recu' => $recu->numero, 'erreur' => $e::class]);
        }
    }

    /**
     * Avis d'un versement annulé par une correction (annulation seule, ou erreur d'auditeur pour l'auditeur qui le perd).
     * Après la validation ; ne lève jamais : un incident d'envoi ne défait pas la correction.
     */
    public function prevenirAnnulation(Versement $versement, ?RecuVersement $recuAnnule, Candidature $dossier, ?User $auteur): void
    {
        try {
            $date = $versement->date_versement->format('d/m/Y');
            $annee = (string) $versement->inscription?->annee_academique;
            $sms = $this->envoyer($dossier, NotificationCandidatsService::CANAL_SMS,
                self::texteSmsAnnulation($recuAnnule?->numero, (int) $versement->montant, $date), null, $auteur, CandidatureRelance::CAUSE_CORRECTION);
            $email = $this->envoyer($dossier, NotificationCandidatsService::CANAL_EMAIL,
                self::texteEmailAnnulation($recuAnnule?->numero, (int) $versement->montant, $date, $annee), self::sujetEmailAnnulation($recuAnnule?->numero),
                $auteur, CandidatureRelance::CAUSE_CORRECTION);
            $bilan = collect($sms)->map(fn (int $n, string $cle): int => $n + (int) ($email[$cle] ?? 0))->all();
            activity('scolarite')->causedBy($auteur)->performedOn($versement)->event('annulation_avis')
                ->withProperties(['recu' => $recuAnnule?->numero] + $bilan)->log('Versement annulé : auditeur prévenu');
        } catch (Throwable $e) {
            Log::channel('single')->error('Avis d\'annulation à l\'auditeur interrompu.', ['versement' => $versement->uuid, 'erreur' => $e::class]);
        }
    }

    /** @return array{sms_envoyes: int, sms_echecs: int, emails_envoyes: int, emails_echecs: int, ignores: int} */
    private function envoyer(Candidature $dossier, string $canal, string $texte, ?string $sujet, ?User $auteur, string $cause = CandidatureRelance::CAUSE_RECU): array
    {
        return $this->notifications->envoyer(collect([$dossier]), $canal, $texte, $sujet, $auteur,
            dejaRendu: true, cause: $cause);
    }

    /** Espace de l'auditeur, sans barre finale. */
    private static function espace(): string
    {
        return rtrim((string) config('pssfp.candidature_app_url'), '/');
    }
}
