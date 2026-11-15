<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\RecuVersement;
use App\Models\User;
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
            $sms = $this->envoyer($dossier, NotificationCandidatsService::CANAL_SMS, self::texteSms($recu->numero, $recu->montant), null, $auteur);
            $email = $this->envoyer($dossier, NotificationCandidatsService::CANAL_EMAIL,
                self::texteEmail($recu->numero, $recu->montant, (string) $recu->annee_academique), self::sujetEmail($recu->numero), $auteur);
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

    /** @return array{sms_envoyes: int, sms_echecs: int, emails_envoyes: int, emails_echecs: int, ignores: int} */
    private function envoyer(Candidature $dossier, string $canal, string $texte, ?string $sujet, ?User $auteur): array
    {
        return $this->notifications->envoyer(collect([$dossier]), $canal, $texte, $sujet, $auteur,
            dejaRendu: true, cause: CandidatureRelance::CAUSE_RECU);
    }

    /** Espace de l'auditeur, sans barre finale. */
    private static function espace(): string
    {
        return rtrim((string) config('pssfp.candidature_app_url'), '/');
    }
}
