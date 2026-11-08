<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\AttributionEcue;
use App\Models\EmploiDuTemps;
use App\Models\EvenementCalendrier;
use App\Models\IndisponibiliteEnseignant;
use App\Models\InscriptionPedagogique;
use App\Models\JourNonOuvre;
use App\Models\QuotaHoraire;
use App\Models\Seance;
use Illuminate\Support\Collection;

/**
 * Conflits et alertes d'un emploi du temps (spec lot 12c, §2.6 ; port de v_conflits_emploi_du_temps).
 * Bloquants : enseignant, salle ou parcours programmés deux fois, séance hors période, pendant une
 * interruption, les examens du niveau ou un jour non ouvré. Le même ECUE à la même heure dans deux
 * parcours du même niveau est un cours commun. Alertes : capacité, attribution, volume.
 * Lot 12d (D22) : séance pendant une indisponibilité de l'enseignant (bloquant) ; séance sans programmation (alerte,
 * dès que la période a un quota).
 */
final class ConflitsEmploiDuTemps
{
    public const LIBELLES = [
        'enseignant_double' => 'Enseignant programmé deux fois',
        'salle_double' => 'Salle occupée deux fois',
        'classe_double' => 'Parcours programmé deux fois',
        'hors_periode' => 'Hors de la période de cours',
        'pendant_evenement' => 'Pendant une interruption ou des examens',
        'jour_non_ouvre' => 'Jour non ouvré',
        'enseignant_indisponible' => 'Enseignant indisponible',
        'capacite_salle' => 'Salle trop petite',
        'enseignant_non_attribue' => 'Enseignant sans attribution sur l\'ECUE',
        'volume_depasse' => 'Volume de la maquette dépassé',
        'seance_sans_programmation' => 'Séance sans programmation de l\'enseignant',
    ];

    /** @return list<array{controle: string, seance: Seance, detail: string, bloquant: bool}> */
    public function pour(EmploiDuTemps $e): array
    {
        $e->loadMissing(['periode.calendrier', 'parcours']);
        $calendrier = $e->periode->calendrier;
        $toutes = Seance::query()->whereIn('statut', Seance::ACTIVES)
            ->whereHas('emploiDuTemps.periode', fn ($q) => $q->where('calendrier_id', $calendrier->id))
            ->with(['emploiDuTemps.parcours', 'ecue', 'salleAffectee', 'enseignant'])->orderBy('date_seance')->orderBy('debut')->get();
        $miennes = $toutes->where('emploi_du_temps_id', $e->id)->values();
        if ($miennes->isEmpty()) {
            return [];
        }

        return [
            ...$this->doubles($miennes, $toutes->groupBy(fn (Seance $s): string => $s->date_seance->toDateString())),
            ...$this->dates($e, $miennes),
            ...$this->indisponibilites($miennes),
            ...$this->alertes($miennes),
            ...$this->programmation($e, $miennes),
        ];
    }

    /**
     * @param  Collection<int, Seance>  $miennes
     * @param  Collection<string, Collection<int, Seance>>  $parJour
     * @return list<array{controle: string, seance: Seance, detail: string, bloquant: bool}>
     */
    private function doubles(Collection $miennes, Collection $parJour): array
    {
        $sortie = [];
        foreach ($miennes as $m) {
            foreach ($parJour->get($m->date_seance->toDateString(), collect()) as $o) {
                if ($o->id === $m->id || ($o->emploi_du_temps_id === $m->emploi_du_temps_id && $o->id < $m->id) || ! $this->chevauche($m, $o)) {
                    continue;
                }
                $commun = $this->coursCommun($m, $o);
                $avec = ' : '.$o->libelle().' ('.$o->emploiDuTemps->parcours->code.')';
                if ($m->enseignant_id !== null && $m->enseignant_id === $o->enseignant_id && ! $commun) {
                    $sortie[] = $this->ligne('enseignant_double', $m, ($m->enseignant?->name ?? 'Enseignant').' déjà programmé'.$avec, true);
                }
                if ($m->salle_id !== null && $m->salle_id === $o->salle_id && ! $commun) {
                    $sortie[] = $this->ligne('salle_double', $m, 'Salle '.$m->salleAffectee?->code.' déjà occupée'.$avec, true);
                }
                if ($m->emploiDuTemps->parcours_id === $o->emploiDuTemps->parcours_id) {
                    $sortie[] = $this->ligne('classe_double', $m, 'Le parcours a déjà une séance'.$avec, true);
                }
            }
        }

        return $sortie;
    }

    /**
     * @param  Collection<int, Seance>  $miennes
     * @return list<array{controle: string, seance: Seance, detail: string, bloquant: bool}>
     */
    private function dates(EmploiDuTemps $e, Collection $miennes): array
    {
        $niveau = $e->parcours->niveau;
        $evenements = $e->periode->calendrier->evenements()->where('statut', '<>', 'annule')
            ->where(fn ($q) => $q->where('type', 'vacances')->orWhere(fn ($x) => $x->whereIn('type', ['examens', 'rattrapage'])
                ->where(fn ($n) => $n->whereNull('niveau')->orWhere('niveau', $niveau))))->get();
        $jours = JourNonOuvre::query()->whereIn('jour', $miennes->map(fn (Seance $s): string => $s->date_seance->toDateString())->unique()->all())
            ->get()->keyBy(fn (JourNonOuvre $j): string => $j->jour->toDateString());
        $sortie = [];
        foreach ($miennes as $s) {
            if (! $s->date_seance->betweenIncluded($e->periode->debut_prevu, $e->periode->fin_prevue)) {
                $sortie[] = $this->ligne('hors_periode', $s, 'Période du '.$e->periode->debut_prevu->format('d/m/Y').' au '.$e->periode->fin_prevue->format('d/m/Y').'.', true);
            }
            $ev = $evenements->first(fn (EvenementCalendrier $v): bool => $s->date_seance->betweenIncluded($v->debut_prevu, $v->fin_prevue));
            if ($ev !== null) {
                $sortie[] = $this->ligne('pendant_evenement', $s, 'Pendant : '.$ev->libelle.'.', true);
            }
            if ($jours->has($s->date_seance->toDateString())) {
                $sortie[] = $this->ligne('jour_non_ouvre', $s, 'Jour non ouvré : '.$jours[$s->date_seance->toDateString()]->libelle.'.', true);
            }
        }

        return $sortie;
    }

    /**
     * @param  Collection<int, Seance>  $miennes
     * @return list<array{controle: string, seance: Seance, detail: string, bloquant: bool}>
     */
    private function alertes(Collection $miennes): array
    {
        $ues = $miennes->map(fn (Seance $s): int => $s->ecue->maquette_ue_id)->unique()->values();
        $inscrits = InscriptionPedagogique::query()->whereIn('maquette_ue_id', $ues)->where('statut', InscriptionPedagogique::INSCRIT)
            ->selectRaw('maquette_ue_id, count(*) as n')->groupBy('maquette_ue_id')->pluck('n', 'maquette_ue_id');
        $attributions = AttributionEcue::query()->whereIn('maquette_ecue_id', $miennes->pluck('maquette_ecue_id')->unique())->get(['maquette_ecue_id', 'user_id'])
            ->map(fn (AttributionEcue $a): string => $a->maquette_ecue_id.'-'.$a->user_id)->flip();
        $sortie = [];
        foreach ($miennes as $s) {
            $n = (int) ($inscrits[$s->ecue->maquette_ue_id] ?? 0);
            $capacite = $s->salleAffectee?->nature === 'presentiel' ? $s->salleAffectee->capacite : null;
            if ($s->modalite !== 'distanciel' && $capacite !== null && $n > $capacite) {
                $sortie[] = $this->ligne('capacite_salle', $s, 'Salle de '.$capacite.' places pour '.$n.' inscrits.', false);
            }
            if ($s->enseignant_id !== null && ! $attributions->has($s->maquette_ecue_id.'-'.$s->enseignant_id)) {
                $sortie[] = $this->ligne('enseignant_non_attribue', $s, ($s->enseignant?->name ?? 'Enseignant').' n\'est pas attribué à '.$s->ecue->code.'.', false);
            }
        }
        foreach ($miennes->groupBy('maquette_ecue_id') as $parEcue) {
            $ecue = $parEcue->first()->ecue;
            $maquette = (int) $ecue->heures_cm + (int) $ecue->heures_td + (int) $ecue->heures_tp;
            $programme = $parEcue->sum(fn (Seance $s): int => $s->duree()) / 60;
            if ($maquette > 0 && $programme > $maquette) {
                $sortie[] = $this->ligne('volume_depasse', $parEcue->last(), $ecue->code.' : '.round($programme, 1).' h programmées pour '.$maquette.' h à la maquette.', false);
            }
        }

        return $sortie;
    }

    /**
     * @param  Collection<int, Seance>  $miennes
     * @return list<array{controle: string, seance: Seance, detail: string, bloquant: bool}>
     */
    private function indisponibilites(Collection $miennes): array
    {
        $indispos = IndisponibiliteEnseignant::query()->whereIn('enseignant_id', $miennes->pluck('enseignant_id')->filter()->unique())->get()->groupBy('enseignant_id');
        $sortie = [];
        foreach ($miennes->whereNotNull('enseignant_id') as $s) {
            $i = $indispos->get($s->enseignant_id, collect())->first(fn (IndisponibiliteEnseignant $x): bool => $x->couvre($s));
            if ($i !== null) {
                $sortie[] = $this->ligne('enseignant_indisponible', $s, ($s->enseignant?->name ?? 'Enseignant').' indisponible '.$i->libelle().'.', true);
            }
        }

        return $sortie;
    }

    /**
     * @param  Collection<int, Seance>  $miennes
     * @return list<array{controle: string, seance: Seance, detail: string, bloquant: bool}>
     */
    private function programmation(EmploiDuTemps $e, Collection $miennes): array
    {
        if (! QuotaHoraire::query()->where('periode_id', $e->periode_id)->exists()) {
            return [];
        }

        return $miennes->whereNull('programmation_id')->map(fn (Seance $s): array => $this->ligne('seance_sans_programmation', $s,
            ($s->enseignant?->name ?? 'Aucun enseignant').' : aucune programmation pour '.$s->ecue->code.' dans la période.', false))->values()->all();
    }

    private function chevauche(Seance $a, Seance $b): bool
    {
        return strcmp(substr((string) $a->debut, 0, 5), substr((string) $b->fin, 0, 5)) < 0
            && strcmp(substr((string) $b->debut, 0, 5), substr((string) $a->fin, 0, 5)) < 0;
    }

    /** Même ECUE (code), mêmes heures, deux parcours du même niveau : cours commun, pas de conflit d'enseignant ni de salle. */
    private function coursCommun(Seance $a, Seance $b): bool
    {
        return $a->ecue->code === $b->ecue->code && $a->emploiDuTemps->parcours_id !== $b->emploiDuTemps->parcours_id
            && $a->emploiDuTemps->parcours->niveau === $b->emploiDuTemps->parcours->niveau
            && substr((string) $a->debut, 0, 5) === substr((string) $b->debut, 0, 5) && substr((string) $a->fin, 0, 5) === substr((string) $b->fin, 0, 5);
    }

    /** @return array{controle: string, seance: Seance, detail: string, bloquant: bool} */
    private function ligne(string $controle, Seance $s, string $detail, bool $bloquant): array
    {
        return ['controle' => $controle, 'seance' => $s, 'detail' => $detail, 'bloquant' => $bloquant];
    }
}
