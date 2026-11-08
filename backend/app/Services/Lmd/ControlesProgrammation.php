<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\EvenementCalendrier;
use App\Models\IndisponibiliteEnseignant;
use App\Models\ProgrammationEnseignant;
use App\Models\QuotaHoraire;
use App\Models\Seance;
use Illuminate\Support\Collection;

/**
 * Les neuf contrôles de la programmation d'une période de cours (spec lot 12d, §2.7 ; port de v_controle_programmation).
 * Non bloquants ; l'indisponibilité bloque en outre la publication de l'emploi du temps (ConflitsEmploiDuTemps).
 */
final class ControlesProgrammation
{
    public const LIBELLES = [
        'repartition_quota' => 'Quota non réparti exactement',
        'quota_annuel_maquette' => 'Quotas de l\'année différents de la maquette',
        'depassement_allocation' => 'Heures programmées au-delà de l\'allocation',
        'charge_max_depassee' => 'Charge au-delà du plafond',
        'fenetre_hors_periode' => 'Fenêtre d\'intervention hors de la période',
        'seance_sans_programmation' => 'Séance sans programmation',
        'enseignant_indisponible' => 'Séance pendant une indisponibilité',
        'seance_hors_fenetre' => 'Séance hors de la fenêtre prévue',
        'non_notifiee' => 'Programmation non notifiée',
    ];

    public function __construct(private readonly Programmations $programmations) {}

    /** @return list<array{controle: string, detail: string}> */
    public function pour(EvenementCalendrier $periode): array
    {
        $quotas = $this->programmations->quotasPeriode($periode);
        $seances = Seance::query()->whereIn('statut', Seance::ACTIVES)->whereHas('emploiDuTemps', fn ($q) => $q->where('periode_id', $periode->id))
            ->with(['ecue', 'enseignant', 'programmation'])->orderBy('date_seance')->orderBy('debut')->get();

        return [
            ...$this->quotas($periode, $quotas),
            ...$this->allocations($periode, collect($quotas)->flatMap(fn (array $l) => $l['quota']->programmations)
                ->filter(fn (ProgrammationEnseignant $p): bool => $p->estActive())->values()),
            ...$this->seances($seances),
        ];
    }

    /**
     * Contrôles 1 et 2 : répartition du quota ; quotas de l'année face au volume de la maquette.
     *
     * @param  list<array{quota: QuotaHoraire, reparti: float, programme: float, tenu: float, avancement: int}>  $quotas
     * @return list<array{controle: string, detail: string}>
     */
    private function quotas(EvenementCalendrier $periode, array $quotas): array
    {
        $sortie = [];
        foreach ($quotas as $l) {
            if (abs($l['reparti'] - $l['quota']->total()) > 0.01) {
                $sortie[] = $this->ligne('repartition_quota', $this->cours($l['quota']).' : quota '.$l['quota']->total().' h, réparti '.$l['reparti'].' h.');
            }
        }
        $ecues = collect($quotas)->map(fn (array $l) => $l['quota']->ecue)->keyBy('id');
        $annee = QuotaHoraire::query()->whereIn('maquette_ecue_id', $ecues->keys())
            ->whereHas('periode', fn ($q) => $q->where('calendrier_id', $periode->calendrier_id))->get()->groupBy('maquette_ecue_id');
        foreach ($annee as $ecueId => $liste) {
            $x = $ecues[$ecueId];
            $maquette = (float) ($x->heures_cm + $x->heures_td + $x->heures_tp);
            $total = round($liste->sum(fn (QuotaHoraire $q): float => $q->total()), 1);
            if (abs($total - $maquette) > 0.01) {
                $sortie[] = $this->ligne('quota_annuel_maquette', $x->code.' : quotas de l\'année '.$total.' h, maquette '.$maquette.' h.');
            }
        }

        return $sortie;
    }

    /**
     * Contrôles 3, 4, 5 et 9 : allocation, plafond, fenêtre, notification.
     *
     * @param  Collection<int, ProgrammationEnseignant>  $actives
     * @return list<array{controle: string, detail: string}>
     */
    private function allocations(EvenementCalendrier $periode, Collection $actives): array
    {
        $conso = $this->programmations->consommation($actives);
        $ouverte = $periode->debut_prevu->lte(now('Africa/Douala')->startOfDay());
        $sortie = [];
        foreach ($actives as $p) {
            $qui = $p->enseignant->name.', '.$p->quota->ecue->code;
            if ($conso[$p->id]['programme'] > $p->total()) {
                $sortie[] = $this->ligne('depassement_allocation', $qui.' : '.$conso[$p->id]['programme'].' h programmées pour '.$p->total().' h allouées.');
            }
            if (($p->debut_prevu !== null && $p->debut_prevu->lt($periode->debut_prevu)) || ($p->fin_prevue !== null && $p->fin_prevue->gt($periode->fin_prevue))) {
                $sortie[] = $this->ligne('fenetre_hors_periode', $qui.' : intervention '.$p->fenetre().', hors de la période.');
            }
            if ($ouverte && in_array($p->statut, [ProgrammationEnseignant::PROPOSE, ProgrammationEnseignant::VALIDE], true)) {
                $sortie[] = $this->ligne('non_notifiee', $qui.' : '.mb_strtolower(ProgrammationEnseignant::STATUTS[$p->statut]).', non notifiée alors que la période est ouverte.');
            }
        }
        foreach ($this->programmations->charges($periode) as $c) {
            if ($c['plafond'] !== null && $c['allouees'] > $c['plafond']) {
                $sortie[] = $this->ligne('charge_max_depassee', $c['enseignant']->name.' : charge de '.$c['allouees'].' h pour un plafond de '.$c['plafond'].' h.');
            }
        }

        return $sortie;
    }

    /**
     * Contrôles 6, 7 et 8 : séance sans programmation, pendant une indisponibilité, hors de la fenêtre.
     *
     * @param  Collection<int, Seance>  $seances
     * @return list<array{controle: string, detail: string}>
     */
    private function seances(Collection $seances): array
    {
        $indispos = IndisponibiliteEnseignant::query()->whereIn('enseignant_id', $seances->pluck('enseignant_id')->filter()->unique())->get()->groupBy('enseignant_id');
        $sortie = [];
        foreach ($seances as $s) {
            $qui = $s->enseignant?->name ?? 'sans enseignant';
            if ($s->programmation_id === null) {
                $sortie[] = $this->ligne('seance_sans_programmation', $s->libelle().' ('.$qui.') : aucune programmation de cet enseignant pour ce cours.');
            }
            $i = $indispos->get($s->enseignant_id, collect())->first(fn (IndisponibiliteEnseignant $x): bool => $x->couvre($s));
            if ($i !== null) {
                $sortie[] = $this->ligne('enseignant_indisponible', $s->libelle().' ('.$qui.') : indisponible '.$i->libelle().'.');
            }
            $p = $s->programmation;
            if ($p !== null && (($p->debut_prevu !== null && $s->date_seance->lt($p->debut_prevu)) || ($p->fin_prevue !== null && $s->date_seance->gt($p->fin_prevue)))) {
                $sortie[] = $this->ligne('seance_hors_fenetre', $s->libelle().' ('.$qui.') : hors de la fenêtre '.$p->fenetre().'.');
            }
        }

        return $sortie;
    }

    private function cours(QuotaHoraire $q): string
    {
        return $q->ecue->code.' ('.$q->ecue->maquetteUe->maquette->parcours->code.')';
    }

    /** @return array{controle: string, detail: string} */
    private function ligne(string $controle, string $detail): array
    {
        return ['controle' => $controle, 'detail' => $detail];
    }
}
