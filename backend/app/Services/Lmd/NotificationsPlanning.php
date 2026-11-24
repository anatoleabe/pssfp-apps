<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Jobs\NotifierPlanning;
use App\Models\Auditeur;
use App\Models\EmploiDuTemps;
use App\Models\EpreuveExamen;
use App\Models\InscriptionAnnuelle;
use App\Models\InscriptionPedagogique;
use App\Models\PlanningExamens;
use App\Models\Seance;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Support\Collection;

/**
 * Notifications des emplois du temps et des plannings d'examens (spec lot 28) : e-mail, et SMS activable.
 * Chaque méthode met une tâche en file et renvoie le nombre d'auditeurs prévenus ; rien ne part pour un brouillon.
 */
final class NotificationsPlanning
{
    public const REGLAGE = 'lmd.notifications_planning';

    public function __construct(private readonly CandidatsExamen $candidats) {}

    /** @return array{email: bool, sms: bool} */
    public static function reglages(): array
    {
        $r = (array) (AppSettings::get(self::REGLAGE) ?? []);

        return ['email' => (bool) ($r['email'] ?? true), 'sms' => (bool) ($r['sms'] ?? false)];
    }

    public static function regler(bool $email, bool $sms, User $auteur): void
    {
        abort_unless($auteur->can('settings.manage'), 403);
        $avant = self::reglages();
        $apres = ['email' => $email, 'sms' => $sms];
        if ($avant === $apres) {
            return;
        }
        AppSettings::set(self::REGLAGE, $apres, (int) $auteur->id);
        activity('lmd')->causedBy($auteur)->event('notifications_planning_reglees')->withProperties(['avant' => $avant, 'apres' => $apres])
            ->log('Notifications des emplois du temps et des examens réglées');
    }

    public function emploiDuTempsPublie(EmploiDuTemps $e): int
    {
        return $this->envoyer($this->auditeursDe($e), 'Emploi du temps publié',
            'PSSFP : l\'emploi du temps de votre parcours pour « '.$e->periode->libelle.' » est publié. Consultez-le dans votre espace : '.$this->lien());
    }

    public function seanceReportee(Seance $ancienne, Seance $nouvelle): int
    {
        if ($ancienne->emploiDuTemps?->statut !== EmploiDuTemps::PUBLIE) {
            return 0;
        }

        return $this->envoyer($this->auditeursDe($ancienne->emploiDuTemps), 'Séance reportée',
            'PSSFP : la séance '.$ancienne->ecue?->code.' du '.$ancienne->date_seance->format('d/m/Y').' est reportée au '.$nouvelle->date_seance->format('d/m/Y')
            .' de '.substr((string) $nouvelle->debut, 0, 5).' à '.substr((string) $nouvelle->fin, 0, 5).'. Votre emploi du temps : '.$this->lien());
    }

    public function seanceAnnulee(Seance $s): int
    {
        if ($s->emploiDuTemps?->statut !== EmploiDuTemps::PUBLIE) {
            return 0;
        }

        return $this->envoyer($this->auditeursDe($s->emploiDuTemps), 'Séance annulée',
            'PSSFP : la séance '.$s->ecue?->code.' du '.$s->date_seance->format('d/m/Y').' à '.substr((string) $s->debut, 0, 5).' est annulée. Votre emploi du temps : '.$this->lien());
    }

    public function planningPublie(PlanningExamens $p): int
    {
        $dossiers = $p->epreuves()->with('planning.session.calendrier')->get()->flatMap(fn (EpreuveExamen $e): Collection => $this->candidatsDe($e));

        return $this->envoyer($dossiers, 'Planning des examens publié',
            'PSSFP : le planning « '.$p->libelle().' » est publié. Vos épreuves et votre salle sont dans votre espace : '.$this->lien());
    }

    public function epreuveDeplacee(EpreuveExamen $e): int
    {
        if (! $e->planning->estPublie()) {
            return 0;
        }

        return $this->envoyer($this->candidatsDe($e), 'Épreuve déplacée',
            'PSSFP : l\'épreuve '.$e->code_ecue.' aura lieu le '.$e->date_epreuve->format('d/m/Y').' de '.substr((string) $e->debut, 0, 5).' à '.substr((string) $e->fin, 0, 5)
            .'. Vos épreuves et votre salle : '.$this->lien());
    }

    /** Dossiers des auditeurs inscrits au parcours pour l'année du calendrier, hors abandons et exclus. @return Collection<int, int> */
    private function auditeursDe(EmploiDuTemps $e): Collection
    {
        return InscriptionAnnuelle::query()->where('annee_academique', $e->periode->calendrier->annee_academique)->where('parcours_id', $e->parcours_id)
            ->whereHas('auditeur', fn ($q) => $q->whereNotIn('statut', Auditeur::STATUTS_SORTIS))
            ->with('auditeur:id,candidature_id')->get()->map(fn (InscriptionAnnuelle $i): int => (int) $i->auditeur->candidature_id);
    }

    /** @return Collection<int, int> */
    private function candidatsDe(EpreuveExamen $e): Collection
    {
        return $this->candidats->pour($e)->map(fn (InscriptionPedagogique $ip): int => (int) $ip->inscriptionAnnuelle?->auditeur?->candidature_id);
    }

    /** @param  Collection<int, int>  $dossiers */
    private function envoyer(Collection $dossiers, string $sujet, string $texte): int
    {
        $reglages = self::reglages();
        $ids = $dossiers->filter()->unique()->values()->all();
        if ($ids === [] || (! $reglages['email'] && ! $reglages['sms'])) {
            return 0;
        }
        NotifierPlanning::dispatch($ids, 'PSSFP — '.$sujet, $texte, $reglages['email'], $reglages['sms'])->afterCommit();

        return count($ids);
    }

    /** Adresse de l'espace : aucune ponctuation ne la suit dans un message (incident du lien SMS, PR #114). */
    private function lien(): string
    {
        return rtrim((string) config('pssfp.candidature_app_url'), '/').'/dossier/emploi-du-temps';
    }
}
