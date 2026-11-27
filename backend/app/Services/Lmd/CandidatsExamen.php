<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Auditeur;
use App\Models\EpreuveExamen;
use App\Models\InscriptionPedagogique;
use App\Models\MaquetteEcue;
use App\Models\PlanningExamens;
use App\Models\RegleEvaluation;
use App\Models\Salle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Candidats d'une épreuve et leur répartition en salles (spec lot 27, règles 2 à 4). Rien n'est stocké :
 * la liste suit les inscriptions pédagogiques, la répartition suit l'ordre alphabétique et les capacités.
 */
final class CandidatsExamen
{
    /** @var array<int, Collection<int, InscriptionPedagogique>> */
    private array $memo = [];

    public function __construct(private readonly SaisieNotes $saisie) {}

    /**
     * ECUE des maquettes validées que couvre la session (année du calendrier, niveau et parcours de l'événement).
     *
     * @return Collection<int, MaquetteEcue>
     */
    public function ecues(PlanningExamens $p, ?string $code = null): Collection
    {
        $session = $p->session;

        return MaquetteEcue::query()
            ->when($code !== null, fn (Builder $q) => $q->where('code', $code))
            ->whereHas('maquetteUe.maquette', fn (Builder $q) => $q->where('statut', 'validee')
                ->where('annee_academique', $session->calendrier->annee_academique)
                ->when($session->parcours_id !== null, fn (Builder $m) => $m->where('parcours_id', $session->parcours_id))
                ->when($session->niveau !== null, fn (Builder $m) => $m->whereHas('parcours', fn (Builder $x) => $x->where('niveau', $session->niveau))))
            ->with('maquetteUe.maquette')->orderBy('code')->get();
    }

    /**
     * Candidats attendus, par ordre alphabétique : inscrits à l'UE de chaque ECUE portant le code ;
     * en rattrapage, les seuls éligibles. Les auditeurs en abandon ou exclus n'y figurent pas.
     *
     * @return Collection<int, InscriptionPedagogique>
     */
    public function pour(EpreuveExamen $e): Collection
    {
        return $this->memo[$e->id] ??= $this->calculer($e);
    }

    /**
     * Répartition : salles remplies dans l'ordre jusqu'à leur capacité ; une salle sans capacité prend le reste.
     *
     * @return array{salles: list<array{salle: Salle, candidats: Collection<int, InscriptionPedagogique>, surveillants: Collection<int, User>}>, sans_place: Collection<int, InscriptionPedagogique>}
     */
    public function repartition(EpreuveExamen $e): array
    {
        $e->loadMissing(['salles', 'surveillants']);
        $reste = $this->pour($e);
        $salles = [];
        foreach ($e->salles as $salle) {
            $n = $salle->capacite ?? $reste->count();
            $salles[] = ['salle' => $salle, 'candidats' => $reste->take($n)->values(),
                'surveillants' => $e->surveillants->filter(fn ($u): bool => (int) $u->pivot->salle_id === $salle->id)->values()];
            $reste = $reste->slice($n)->values();
        }

        return ['salles' => $salles, 'sans_place' => $reste];
    }

    /** Les inscriptions ou le code d'une épreuve ont pu changer : la liste se recalcule. */
    public function oublier(): void
    {
        $this->memo = [];
    }

    public static function nom(InscriptionPedagogique $ip): string
    {
        $d = $ip->inscriptionAnnuelle?->auditeur?->candidature;

        return trim(mb_strtoupper((string) $d?->nom).' '.$d?->prenom);
    }

    /** @return Collection<int, InscriptionPedagogique> */
    private function calculer(EpreuveExamen $e): Collection
    {
        $e->loadMissing('planning.session.calendrier');
        $rattrapage = $e->planning->estRattrapage();
        $regle = $rattrapage ? RegleEvaluation::pourAnnee($e->planning->session->calendrier->annee_academique) : null;

        return $this->ecues($e->planning, $e->code_ecue)
            ->flatMap(fn (MaquetteEcue $x): Collection => $this->saisie->inscrits($x))
            ->filter(fn (InscriptionPedagogique $ip): bool => ! in_array($ip->inscriptionAnnuelle?->auditeur?->statut, Auditeur::STATUTS_SORTIS, true)
                && (! $rattrapage || ($regle !== null && $this->saisie->resultat($ip, $regle)->eligibleRattrapage)))
            ->unique('inscription_annuelle_id')
            ->sortBy(fn (InscriptionPedagogique $ip): string => self::nom($ip))
            ->values();
    }
}
