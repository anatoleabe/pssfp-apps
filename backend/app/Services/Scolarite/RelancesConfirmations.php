<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use App\Services\NotificationCandidatsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Relance des versements non confirmés (spec module-4-scolarite-encaissement-confirmation §2.2 point 7) :
 * chaque matin, un SMS et un e-mail à l'auditeur dont un versement attend sa confirmation depuis 7 jours,
 * au plus une fois tous les 7 jours. Circuit des messages aux auditeurs : tracé au journal des envois
 * (motif « confirmation ») et réaffiché dans son espace. Le contrôle interne VER_NON_CONFIRMES relève les
 * mêmes versements pour la scolarité, parts des règlements d'organismes comprises (jamais relancées auprès de l'auditeur).
 */
final class RelancesConfirmations
{
    public const DELAI_JOURS = 7;

    /** ASCII, un seul segment, sans téléphone ni adresse. */
    public const TEXTE_SMS = 'PSSFP : votre versement n\'est pas encore confirme. Presentez votre recu bancaire original a la scolarite pour le faire valider.';

    public const SUJET_EMAIL = 'PSSFP — Versement à confirmer à la scolarité';

    public function __construct(private readonly NotificationCandidatsService $notifications) {}

    /**
     * Enregistré avant ce moment, un versement non confirmé a 7 jours : début du jour J-6 à Yaoundé (enregistré
     * le 10, relancé le 17 au matin), ramené au fuseau de l'application, la requête écrivant la date sans fuseau.
     */
    public static function seuil(): CarbonImmutable
    {
        return InscriptionAnnuelle::aujourdhui()->subDays(self::DELAI_JOURS - 1)->setTimezone((string) config('app.timezone'));
    }

    /**
     * @param  Collection<int, Versement>  $versements  à confirmer, avec leurs reçus
     */
    public static function texteEmail(Collection $versements): string
    {
        $lignes = $versements->map(fn (Versement $v): string => '- '.RecusVersements::fcfa($v->montant).' versés le '.$v->date_versement->format('d/m/Y')
            .', bordereau '.$v->reference.(($r = $v->recuEnVigueur()) === null ? '' : ' (reçu '.$r->numero.')'));

        return ($versements->count() > 1 ? 'Ces versements n\'ont' : 'Ce versement n\'a')." pas encore été confirmé par la scolarité :\n"
            .$lignes->implode("\n")."\n\n"
            .'Présentez votre reçu bancaire original à la scolarité pour le faire confirmer. Tant qu\'un versement n\'est pas confirmé, il ne compte pas pour votre accès aux cours.'."\n\n"
            .'Votre situation est dans votre espace : '.rtrim((string) config('pssfp.candidature_app_url'), '/');
    }

    /** @return array{relances: int, ignores: int, echecs: int} */
    public function relancer(): array
    {
        $bilan = ['relances' => 0, 'ignores' => 0, 'echecs' => 0];
        $seuil = self::seuil();
        // Les parts d'un règlement d'organisme (lot A2) n'ont pas de reçu de l'auditeur : le contrôle interne les suit.
        $aConfirmer = fn ($q) => $q->whereNull('annule_at')->whereNull('reglement_organisme_id')->whereDoesntHave('confirmation');

        $auditeurs = Auditeur::query()
            ->whereHas('versements', fn ($q) => $aConfirmer($q)->where('created_at', '<', $seuil))
            ->with(['candidature', 'versements' => fn ($q) => $aConfirmer($q)->with('recus.annulation')->orderBy('date_versement')->orderBy('id')])
            ->orderBy('id')->get();

        foreach ($auditeurs as $auditeur) {
            if (! MessagesAuditeurs::destinataire($auditeur)) {
                $bilan['ignores']++;

                continue;
            }
            if ($this->relanceRecente($auditeur->candidature, $seuil)) {
                continue;
            }

            $rapport = $this->envoyer($auditeur->candidature, $auditeur->versements);
            if ($rapport['sms_envoyes'] + $rapport['emails_envoyes'] === 0) {
                // Passerelle en panne ou aucune coordonnée : nouvel essai demain.
                $bilan['echecs']++;

                continue;
            }
            activity('scolarite')->performedOn($auditeur)->event('confirmation_relance')
                ->withProperties(['versements' => $auditeur->versements->count()] + $rapport)
                ->log('Versement à confirmer : auditeur relancé par SMS et par e-mail');
            $bilan['relances']++;
        }

        return $bilan;
    }

    /** Relance aboutie depuis le début du jour J-6 : la suivante attend 7 jours. */
    private function relanceRecente(Candidature $dossier, CarbonImmutable $seuil): bool
    {
        return CandidatureRelance::query()->where('candidature_id', $dossier->id)->where('cause', CandidatureRelance::CAUSE_CONFIRMATION)
            ->abouties()->where('sent_at', '>=', $seuil)->exists();
    }

    /**
     * @param  Collection<int, Versement>  $versements
     * @return array{sms_envoyes: int, sms_echecs: int, emails_envoyes: int, emails_echecs: int, ignores: int}
     */
    private function envoyer(Candidature $dossier, Collection $versements): array
    {
        $sms = $this->notifications->envoyer(collect([$dossier]), NotificationCandidatsService::CANAL_SMS, self::TEXTE_SMS, null, null,
            dejaRendu: true, cause: CandidatureRelance::CAUSE_CONFIRMATION);
        $email = $this->notifications->envoyer(collect([$dossier]), NotificationCandidatsService::CANAL_EMAIL, self::texteEmail($versements), self::SUJET_EMAIL, null,
            dejaRendu: true, cause: CandidatureRelance::CAUSE_CONFIRMATION);

        return collect($sms)->map(fn (int $n, string $cle): int => $n + (int) ($email[$cle] ?? 0))->all();
    }
}
