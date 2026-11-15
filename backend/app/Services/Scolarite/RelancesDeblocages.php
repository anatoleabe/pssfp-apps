<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\CandidatureRelance;
use App\Models\DeblocageAcces;
use App\Models\InscriptionAnnuelle;
use App\Services\NotificationCandidatsService;

/**
 * Relances des accès anticipés échus (spec module 4, lot A1 §5), chaque matin :
 * un SMS et un e-mail à l'auditeur dont l'échéance est passée, puis tous les
 * 7 jours tant que rien n'est réglé. Les envois suivent le circuit des messages
 * aux auditeurs : même texte sur les deux canaux, tracé au journal des messages
 * et réaffiché dans l'espace de l'auditeur. Un auditeur soldé entre-temps est
 * régularisé au lieu d'être relancé.
 */
final class RelancesDeblocages
{
    public const INTERVALLE_JOURS = 7;

    public const SUJET_EMAIL = 'PSSFP — Vos droits d\'inscription';

    /** ASCII, un seul segment, sans numéro de téléphone ni adresse. */
    public const TEXTES_SMS = [
        DeblocageAcces::VIREMENT_ANNONCE => 'PSSFP : votre virement des droits d\'inscription n\'est pas encore arrive. Merci de regulariser ou de contacter la scolarite.',
        DeblocageAcces::MORATOIRE => 'PSSFP : le delai accorde pour vos droits d\'inscription est depasse. Merci de regulariser aupres de la scolarite.',
        DeblocageAcces::PRISE_EN_CHARGE => 'PSSFP : le reglement de vos droits par votre employeur n\'est pas encore arrive. Merci de le relancer ou de contacter la scolarite.',
    ];

    public function __construct(
        private readonly DeblocagesAcces $deblocages,
        private readonly NotificationCandidatsService $notifications,
    ) {}

    public static function texteSms(string $type): string
    {
        return self::TEXTES_SMS[$type] ?? self::TEXTES_SMS[DeblocageAcces::MORATOIRE];
    }

    /**
     * @return array{relances: int, regularises: int, ignores: int, echecs: int}
     */
    public function relancer(): array
    {
        $bilan = ['relances' => 0, 'regularises' => 0, 'ignores' => 0, 'echecs' => 0];

        // Rattrapage : tout déblocage en cours dont les droits sont soldés, échu ou non (une
        // régularisation manquée après un versement laisserait sinon l'accès ouvert par lui).
        $soldes = DeblocageAcces::query()->enCours()->whereHas('inscription', fn ($i) => $i->inscriptionSoldee())->with('inscription')->get();
        foreach ($soldes as $d) {
            $bilan['regularises'] += $this->deblocages->regulariser($d->inscription) ? 1 : 0;
        }

        // Relancé avant ce jour (heure de Yaoundé) : 7 jours sont passés. Converti au fuseau de
        // l'application, car la requête écrit la date sans fuseau.
        $seuil = InscriptionAnnuelle::aujourdhui()->subDays(self::INTERVALLE_JOURS - 1)->setTimezone((string) config('app.timezone'));

        $dus = DeblocageAcces::query()->echus()
            ->where(fn ($q) => $q->whereNull('derniere_relance_le')->orWhere('derniere_relance_le', '<', $seuil))
            ->with('inscription.auditeur.candidature')
            ->orderBy('id')
            ->get();

        foreach ($dus as $d) {
            if ($this->deblocages->regulariser($d->inscription)) {
                $bilan['regularises']++;

                continue;
            }
            $auditeur = $d->inscription?->auditeur;
            if ($auditeur === null || ! MessagesAuditeurs::destinataire($auditeur)) {
                $bilan['ignores']++;

                continue;
            }

            $rapport = $this->notifications->envoyer(
                collect([$auditeur->candidature]),
                NotificationCandidatsService::CANAL_LES_DEUX,
                self::texteSms($d->type),
                self::SUJET_EMAIL,
                null,
                dejaRendu: true,
                cause: CandidatureRelance::CAUSE_DEBLOCAGE,
            );
            if ($rapport['sms_envoyes'] + $rapport['emails_envoyes'] === 0) {
                // Passerelle en panne ou aucune coordonnée : nouvel essai demain.
                $bilan['echecs']++;

                continue;
            }

            $d->forceFill(['relances' => $d->relances + 1, 'derniere_relance_le' => now()])->save();
            activity('scolarite')->performedOn($d)
                ->event('deblocage_relance')
                ->withProperties(['relance' => $d->relances] + $rapport)
                ->log('Accès anticipé échu : auditeur relancé par SMS et par e-mail');
            $bilan['relances']++;
        }

        return $bilan;
    }
}
