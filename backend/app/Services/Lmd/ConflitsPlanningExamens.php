<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\EpreuveExamen;
use App\Models\InscriptionPedagogique;
use App\Models\JourNonOuvre;
use App\Models\PlanningExamens;
use App\Models\Seance;
use Illuminate\Support\Collection;

/**
 * Contrôles d'un planning d'examens (spec lot 27, règles 6 et 7). Bloquants : hors session, jour non ouvré,
 * épreuve sans salle, capacité insuffisante, salle, surveillant ou candidat pris par deux épreuves qui se
 * chevauchent — tous plannings confondus. Alertes : salle sans surveillant, salle occupée par un cours.
 */
final class ConflitsPlanningExamens
{
    public const LIBELLES = [
        'hors_session' => 'Hors des dates de la session',
        'jour_non_ouvre' => 'Jour non ouvré',
        'sans_salle' => 'Épreuve sans salle',
        'capacite' => 'Capacité insuffisante',
        'salle_double' => 'Salle prise par deux épreuves',
        'surveillant_double' => 'Surveillant placé deux fois',
        'candidat_double' => 'Candidats convoqués à deux épreuves',
        'sans_surveillant' => 'Salle sans surveillant',
        'salle_cours' => 'Salle occupée par un cours',
    ];

    public function __construct(private readonly CandidatsExamen $candidats) {}

    /** @return list<array{controle: string, epreuve: EpreuveExamen, detail: string, bloquant: bool}> */
    public function pour(PlanningExamens $p): array
    {
        $this->candidats->oublier();
        $p->loadMissing('session.calendrier');
        $miennes = $p->epreuves()->with(['salles', 'surveillants', 'planning.session.calendrier'])->get();
        if ($miennes->isEmpty()) {
            return [];
        }
        $jours = $miennes->map(fn (EpreuveExamen $e): string => $e->date_epreuve->toDateString())->unique()->values();
        $autres = EpreuveExamen::query()->where('planning_examens_id', '<>', $p->id)->whereIn('date_epreuve', $jours->all())
            ->with(['salles', 'surveillants', 'planning.session.calendrier'])->get();

        return [...$this->dates($p, $miennes, $jours), ...$this->places($miennes), ...$this->doubles($miennes, $miennes->concat($autres)), ...$this->alertes($miennes, $jours)];
    }

    /** @return list<array{controle: string, epreuve: EpreuveExamen, detail: string, bloquant: bool}> */
    public function bloquants(PlanningExamens $p): array
    {
        return array_values(array_filter($this->pour($p), fn (array $k): bool => $k['bloquant']));
    }

    /**
     * @param  Collection<int, EpreuveExamen>  $miennes
     * @param  Collection<int, string>  $jours
     * @return list<array{controle: string, epreuve: EpreuveExamen, detail: string, bloquant: bool}>
     */
    private function dates(PlanningExamens $p, Collection $miennes, Collection $jours): array
    {
        $s = $p->session;
        $nonOuvres = JourNonOuvre::query()->whereIn('jour', $jours->all())->get()->keyBy(fn (JourNonOuvre $j): string => $j->jour->toDateString());
        $sortie = [];
        foreach ($miennes as $e) {
            if (! $e->date_epreuve->betweenIncluded($s->debut_prevu, $s->fin_prevue)) {
                $sortie[] = $this->ligne('hors_session', $e, 'Session du '.$s->debut_prevu->format('d/m/Y').' au '.$s->fin_prevue->format('d/m/Y').'.', true);
            }
            $jour = $nonOuvres->get($e->date_epreuve->toDateString());
            if ($jour !== null) {
                $sortie[] = $this->ligne('jour_non_ouvre', $e, 'Jour non ouvré : '.$jour->libelle.'.', true);
            }
        }

        return $sortie;
    }

    /**
     * @param  Collection<int, EpreuveExamen>  $miennes
     * @return list<array{controle: string, epreuve: EpreuveExamen, detail: string, bloquant: bool}>
     */
    private function places(Collection $miennes): array
    {
        $sortie = [];
        foreach ($miennes as $e) {
            if ($e->salles->isEmpty()) {
                $sortie[] = $this->ligne('sans_salle', $e, 'Choisissez au moins une salle.', true);

                continue;
            }
            $r = $this->candidats->repartition($e);
            if ($r['sans_place']->isNotEmpty()) {
                $sortie[] = $this->ligne('capacite', $e, $this->candidats->pour($e)->count().' candidats pour '.$e->salles->sum('capacite').' places : '
                    .$r['sans_place']->count().' sans place.', true);
            }
        }

        return $sortie;
    }

    /**
     * @param  Collection<int, EpreuveExamen>  $miennes
     * @param  Collection<int, EpreuveExamen>  $toutes
     * @return list<array{controle: string, epreuve: EpreuveExamen, detail: string, bloquant: bool}>
     */
    private function doubles(Collection $miennes, Collection $toutes): array
    {
        $sortie = [];
        foreach ($miennes as $m) {
            foreach ($toutes as $o) {
                if ($o->id === $m->id || ($o->planning_examens_id === $m->planning_examens_id && $o->id < $m->id) || ! $m->chevauche($o)) {
                    continue;
                }
                $avec = ' : '.$o->libelle();
                $salles = $m->salles->whereIn('id', $o->salles->modelKeys());
                if ($salles->isNotEmpty()) {
                    $sortie[] = $this->ligne('salle_double', $m, 'Salle '.$salles->pluck('code')->implode(', ').' déjà prise'.$avec, true);
                }
                $surveillants = $m->surveillants->whereIn('id', $o->surveillants->modelKeys());
                if ($surveillants->isNotEmpty()) {
                    $sortie[] = $this->ligne('surveillant_double', $m, $surveillants->pluck('name')->implode(', ').' déjà placé(s)'.$avec, true);
                }
                $communs = $this->candidats->pour($m)->whereIn('inscription_annuelle_id', $this->candidats->pour($o)->pluck('inscription_annuelle_id')->all());
                if ($communs->isNotEmpty()) {
                    $sortie[] = $this->ligne('candidat_double', $m, $communs->count().' candidat(s) aussi convoqué(s)'.$avec.' ('
                        .$communs->take(3)->map(fn (InscriptionPedagogique $ip): string => CandidatsExamen::nom($ip))->implode(', ').($communs->count() > 3 ? '…' : '').')', true);
                }
            }
        }

        return $sortie;
    }

    /**
     * @param  Collection<int, EpreuveExamen>  $miennes
     * @param  Collection<int, string>  $jours
     * @return list<array{controle: string, epreuve: EpreuveExamen, detail: string, bloquant: bool}>
     */
    private function alertes(Collection $miennes, Collection $jours): array
    {
        $cours = Seance::query()->whereIn('statut', Seance::ACTIVES)->whereIn('date_seance', $jours->all())->whereNotNull('salle_id')
            ->with('ecue:id,code')->get()->groupBy(fn (Seance $s): string => $s->date_seance->toDateString());
        $sortie = [];
        foreach ($miennes as $e) {
            $sans = $e->salles->whereNotIn('id', $e->surveillants->map(fn ($u): int => (int) $u->pivot->salle_id)->all());
            if ($sans->isNotEmpty()) {
                $sortie[] = $this->ligne('sans_surveillant', $e, 'Aucun surveillant en '.$sans->pluck('code')->implode(', ').'.', false);
            }
            foreach ($cours->get($e->date_epreuve->toDateString(), collect()) as $s) {
                $chevauche = strcmp(substr((string) $e->debut, 0, 5), substr((string) $s->fin, 0, 5)) < 0 && strcmp(substr((string) $s->debut, 0, 5), substr((string) $e->fin, 0, 5)) < 0;
                if ($chevauche && $e->salles->contains('id', $s->salle_id)) {
                    $sortie[] = $this->ligne('salle_cours', $e, 'Cours '.$s->ecue?->code.' en '.$s->salle.' de '.substr((string) $s->debut, 0, 5).' à '.substr((string) $s->fin, 0, 5).'.', false);
                }
            }
        }

        return $sortie;
    }

    /** @return array{controle: string, epreuve: EpreuveExamen, detail: string, bloquant: bool} */
    private function ligne(string $controle, EpreuveExamen $e, string $detail, bool $bloquant): array
    {
        return ['controle' => $controle, 'epreuve' => $e, 'detail' => $detail, 'bloquant' => $bloquant];
    }
}
