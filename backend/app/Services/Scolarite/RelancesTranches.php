<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\DeblocageAcces;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Services\NotificationCandidatsService;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Relance automatique des tranches échues (spec lot 23) : éteinte par défaut, allumée dans Paramètres.
 *
 * Un auditeur actif, inscrit dans l'année en cours, dont une tranche a dépassé son échéance sans être soldée,
 * reçoit un SMS et un e-mail, puis plus rien avant l'intervalle réglé. Les droits d'inscription n'ont pas
 * d'échéance datée : ils ne déclenchent rien.
 */
final class RelancesTranches
{
    public const REGLAGE = 'scolarite.relances_tranches';

    public const INTERVALLE_DEFAUT = 7;

    public const INTERVALLE_MIN = 3;

    public const INTERVALLE_MAX = 30;

    /** Relances par passage : une passerelle saturée ou une erreur de barème ne vide pas le crédit SMS en un matin. */
    public const MAX_PAR_JOUR = 200;

    public const EVENEMENT = 'tranche_relance';

    public const TEXTE_SMS = 'PSSFP : {prenom}, une echeance de votre scolarite est depassee. Il reste {montant} FCFA a regler. Merci de regulariser ou de contacter la scolarite.';

    public const SUJET_EMAIL = 'PSSFP — Échéance de scolarité dépassée';

    public function __construct(private readonly NotificationCandidatsService $notifications) {}

    /** @return array{actif: bool, intervalle_jours: int} */
    public static function reglages(): array
    {
        $r = (array) (AppSettings::get(self::REGLAGE) ?? []);
        $jours = (int) ($r['intervalle_jours'] ?? self::INTERVALLE_DEFAUT);

        return [
            'actif' => (bool) ($r['actif'] ?? false),
            'intervalle_jours' => $jours >= self::INTERVALLE_MIN && $jours <= self::INTERVALLE_MAX ? $jours : self::INTERVALLE_DEFAUT,
        ];
    }

    /** @throws InvalidArgumentException intervalle hors bornes */
    public static function regler(bool $actif, int $intervalle, User $auteur): void
    {
        abort_unless($auteur->can('settings.manage'), 403);
        if ($intervalle < self::INTERVALLE_MIN || $intervalle > self::INTERVALLE_MAX) {
            throw new InvalidArgumentException('Intervalle entre deux relances : de '.self::INTERVALLE_MIN.' à '.self::INTERVALLE_MAX.' jours.');
        }
        $avant = self::reglages();
        $apres = ['actif' => $actif, 'intervalle_jours' => $intervalle];
        if ($avant === $apres) {
            return;
        }
        AppSettings::set(self::REGLAGE, $apres, (int) $auteur->id);
        activity('scolarite')->causedBy($auteur)->event('relances_tranches_reglees')
            ->withProperties(['avant' => $avant, 'apres' => $apres])
            ->log($actif ? 'Relance automatique des tranches activée' : 'Relance automatique des tranches désactivée');
    }

    /** Montant des tranches échues non soldées : hors droits d'inscription, qui n'ont pas d'échéance datée. */
    public static function montantEchu(SituationFinanciere $situation): int
    {
        if ($situation->suspendue) {
            return 0;
        }
        $echu = 0;
        foreach ($situation->paliers as $palier) {
            if ($palier->cle !== Palier::INSCRIPTION && $palier->enRetard) {
                $echu += $palier->reste;
            }
        }

        return $echu;
    }

    public static function texteEmail(Candidature $dossier, SituationFinanciere $situation): string
    {
        $lignes = [];
        foreach ($situation->paliers as $palier) {
            if ($palier->cle !== Palier::INSCRIPTION && $palier->enRetard) {
                $lignes[] = '- '.$palier->libelle().' : '.RecusVersements::fcfa($palier->reste).' restants, échéance du '.$palier->echeance?->format('d/m/Y');
            }
        }

        return 'Bonjour '.$dossier->prenom.",\n\n"
            ."Une échéance de vos frais de scolarité est dépassée :\n".implode("\n", $lignes)."\n\n"
            ."Merci de régulariser, ou de contacter la scolarité si vous rencontrez une difficulté.\n\n"
            .'Votre situation est dans votre espace : '.rtrim((string) config('pssfp.candidature_app_url'), '/');
    }

    /** @return array{actif: bool, relances: int, ignores: int, echecs: int, reportes: int} */
    public function relancer(): array
    {
        $reglages = self::reglages();
        $bilan = ['actif' => $reglages['actif'], 'relances' => 0, 'ignores' => 0, 'echecs' => 0, 'reportes' => 0];
        $annee = AnneeEnCours::annee();
        if (! $reglages['actif'] || $annee === null) {
            return $bilan;
        }
        // Relancé avant ce jour (heure de Yaoundé) : l'intervalle est passé. Ramené au fuseau de l'application.
        $seuil = InscriptionAnnuelle::aujourdhui()->subDays($reglages['intervalle_jours'] - 1)->setTimezone((string) config('app.timezone'));

        $inscriptions = InscriptionAnnuelle::query()->where('annee_academique', $annee)->enRetard()
            ->whereHas('auditeur', fn ($q) => $q->where('statut', Auditeur::STATUT_ACTIF))
            ->whereDoesntHave('deblocages', fn ($q) => $q->where('statut', DeblocageAcces::EN_COURS))
            ->with(['auditeur.candidature', 'bareme'])
            ->withSum('imputationsActives', 'montant')->withSum('imputationsConfirmees', 'montant')
            ->orderBy('id')->get();

        foreach ($inscriptions as $inscription) {
            $auditeur = $inscription->auditeur;
            $situation = $inscription->situation();
            $montant = self::montantEchu($situation);
            if (! MessagesAuditeurs::destinataire($auditeur) || $montant <= 0 || $situation->confirmationEnAttente()) {
                $bilan['ignores']++;

                continue;
            }
            if ($this->relanceRecente($auditeur->candidature, $seuil)) {
                continue;
            }
            if ($bilan['relances'] >= self::MAX_PAR_JOUR) {
                $bilan['reportes']++;

                continue;
            }

            $rapport = $this->envoyer($auditeur->candidature, $situation, $montant);
            if ($rapport['sms_envoyes'] + $rapport['emails_envoyes'] === 0) {
                // Passerelle en panne ou aucune coordonnée : nouvel essai demain.
                $bilan['echecs']++;

                continue;
            }
            activity('scolarite')->performedOn($auditeur)->event(self::EVENEMENT)
                ->withProperties(['annee' => $annee, 'montant_echu' => $montant] + $rapport)
                ->log('Tranche échue : auditeur relancé par SMS et par e-mail');
            $bilan['relances']++;
        }

        return $bilan;
    }

    private function relanceRecente(Candidature $dossier, CarbonImmutable $seuil): bool
    {
        return CandidatureRelance::query()->where('candidature_id', $dossier->id)->where('cause', CandidatureRelance::CAUSE_TRANCHE)
            ->abouties()->where('sent_at', '>=', $seuil)->exists();
    }

    /** @return array{sms_envoyes: int, sms_echecs: int, emails_envoyes: int, emails_echecs: int, ignores: int} */
    private function envoyer(Candidature $dossier, SituationFinanciere $situation, int $montant): array
    {
        $texte = strtr(self::TEXTE_SMS, ['{prenom}' => (string) $dossier->prenom, '{montant}' => number_format($montant, 0, ',', ' ')]);
        $sms = $this->notifications->envoyer(collect([$dossier]), NotificationCandidatsService::CANAL_SMS, $texte, null, null,
            dejaRendu: true, cause: CandidatureRelance::CAUSE_TRANCHE);
        $email = $this->notifications->envoyer(collect([$dossier]), NotificationCandidatsService::CANAL_EMAIL, self::texteEmail($dossier, $situation), self::SUJET_EMAIL, null,
            dejaRendu: true, cause: CandidatureRelance::CAUSE_TRANCHE);

        return collect($sms)->map(fn (int $n, string $cle): int => $n + (int) ($email[$cle] ?? 0))->all();
    }
}
