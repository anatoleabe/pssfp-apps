<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\EpreuveExamen;
use App\Models\EvenementCalendrier;
use App\Models\MaquetteEcue;
use App\Models\PlageHoraire;
use App\Models\PlanningExamens;
use App\Models\Salle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Planning d'examens (spec lot 27) : un planning par session d'examens du calendrier ; épreuves, salles
 * ordonnées, surveillants ; publication refusée tant qu'il reste un conflit bloquant.
 */
final class PlanningsExamens
{
    public function __construct(private readonly CandidatsExamen $candidats, private readonly ConflitsPlanningExamens $conflits) {}

    public function creer(EvenementCalendrier $session, User $auteur): PlanningExamens
    {
        $this->autoriser($auteur);
        if (! in_array($session->type, PlanningExamens::TYPES_SESSION, true) || ! $session->calendrier->estSigne()) {
            throw new InvalidArgumentException('Un planning porte sur une session d\'examens ou de rattrapage d\'un calendrier signé.');
        }
        if (PlanningExamens::query()->where('session_id', $session->id)->exists()) {
            throw new InvalidArgumentException('Le planning de cette session existe déjà.');
        }
        $p = new PlanningExamens;
        $p->forceFill(['session_id' => $session->id, 'cree_par' => $auteur->id])->save();

        return $p;
    }

    /** Codes d'ECUE que la session peut examiner, avec leur intitulé. @return array<string, string> */
    public function codes(PlanningExamens $p): array
    {
        return $this->candidats->ecues($p)->unique('code')->mapWithKeys(fn (MaquetteEcue $x): array => [$x->code => $x->code.' — '.$x->intitule])->all();
    }

    /** Comptes qui peuvent surveiller : tout compte de la plateforme, sauf les candidats. @return Builder<User> */
    public function surveillantsPossibles(): Builder
    {
        return User::query()->whereHas('roles', fn (Builder $q) => $q->where('name', '<>', 'candidat'))->orderBy('name');
    }

    /**
     * Ajoute une épreuve, ou modifie `$epreuve`. Sur un planning publié, la modification est refusée si elle
     * crée un conflit bloquant.
     *
     * @param  array{code_ecue?: string, date_epreuve?: string, plage_horaire_id?: int|string|null, debut?: string|null, fin?: string|null, salles?: list<array{salle_id?: int|string|null, surveillants?: list<int|string>|null}>|null}  $data
     */
    public function enregistrer(PlanningExamens $p, array $data, User $auteur, ?EpreuveExamen $epreuve = null): EpreuveExamen
    {
        $this->autoriser($auteur);
        $champs = $this->champs($p, $data, $epreuve);
        $salles = $this->salles($data['salles'] ?? []);

        return DB::transaction(function () use ($p, $champs, $salles, $auteur, $epreuve): EpreuveExamen {
            $e = $epreuve ?? (new EpreuveExamen)->forceFill(['planning_examens_id' => $p->id, 'cree_par' => $auteur->id]);
            $e->forceFill($champs)->save();
            $e->salles()->sync(collect($salles)->keys()->mapWithKeys(fn (int $id, int $ordre): array => [$id => ['ordre' => $ordre + 1]])->all());
            $e->surveillants()->detach();
            foreach ($salles as $salleId => $surveillants) {
                $e->surveillants()->attach(array_fill_keys($surveillants, ['salle_id' => $salleId]));
            }
            $e->unsetRelations();
            if ($p->estPublie()) {
                // Un planning publié n'a aucun conflit : tout conflit bloquant vient de cette modification.
                $bloquants = $this->conflits->bloquants($p);
                if ($bloquants !== []) {
                    throw new InvalidArgumentException('Modification refusée, le planning est publié : '.$this->resume($bloquants));
                }
            }

            return $e;
        });
    }

    /** Une épreuve ne se supprime que dans un brouillon. */
    public function supprimer(EpreuveExamen $e, User $auteur): void
    {
        $this->autoriser($auteur);
        if ($e->planning->estPublie()) {
            throw new InvalidArgumentException('Le planning est publié : une épreuve se reporte, elle ne se supprime plus.');
        }
        $e->delete();
    }

    public function publier(PlanningExamens $p, User $auteur): void
    {
        $this->autoriser($auteur);
        if ($p->estPublie()) {
            throw new InvalidArgumentException('Planning déjà publié.');
        }
        if (! $p->epreuves()->exists()) {
            throw new InvalidArgumentException('Ajoutez au moins une épreuve avant de publier.');
        }
        $bloquants = $this->conflits->bloquants($p);
        if ($bloquants !== []) {
            throw new InvalidArgumentException('Publication refusée : '.count($bloquants).' conflit(s). '.$this->resume($bloquants));
        }
        $p->forceFill(['statut' => PlanningExamens::PUBLIE, 'publie_par' => $auteur->id, 'publie_at' => now()])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function champs(PlanningExamens $p, array $data, ?EpreuveExamen $epreuve): array
    {
        $code = (string) ($data['code_ecue'] ?? '');
        $ecue = $this->candidats->ecues($p, $code)->first();
        if ($code === '' || $ecue === null) {
            throw new InvalidArgumentException('ECUE absent des maquettes validées que couvre cette session.');
        }
        if ($p->epreuves()->where('code_ecue', $code)->when($epreuve !== null, fn ($q) => $q->whereKeyNot($epreuve->id))->exists()) {
            throw new InvalidArgumentException('L\'épreuve '.$code.' figure déjà au planning.');
        }
        if (empty($data['date_epreuve'])) {
            throw new InvalidArgumentException('Indiquez la date de l\'épreuve.');
        }
        $plage = empty($data['plage_horaire_id']) ? null : PlageHoraire::query()->findOrFail($data['plage_horaire_id']);
        $debut = substr((string) ($plage?->heure_debut ?? $data['debut'] ?? ''), 0, 5);
        $fin = substr((string) ($plage?->heure_fin ?? $data['fin'] ?? ''), 0, 5);
        if ($debut === '' || $fin === '' || strcmp($fin, $debut) <= 0) {
            throw new InvalidArgumentException('Choisissez une plage horaire, ou une heure de fin après l\'heure de début.');
        }

        return ['code_ecue' => $code, 'intitule' => (string) $ecue->intitule, 'date_epreuve' => CarbonImmutable::parse((string) $data['date_epreuve'])->toDateString(),
            'debut' => $debut, 'fin' => $fin];
    }

    /**
     * Salles actives, dans l'ordre saisi, avec leurs surveillants ; un surveillant ne tient qu'une salle.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, list<int>>
     */
    private function salles(array $lignes): array
    {
        $ids = array_values(array_filter(array_map(fn (array $l): int => (int) ($l['salle_id'] ?? 0), $lignes)));
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Une salle ne figure qu\'une fois par épreuve.');
        }
        if (Salle::query()->where('active', true)->whereKey($ids)->count() !== count($ids)) {
            throw new InvalidArgumentException('Salle inconnue ou désactivée.');
        }
        $sortie = [];
        $vus = [];
        foreach ($lignes as $l) {
            if (empty($l['salle_id'])) {
                continue;
            }
            $surveillants = array_values(array_unique(array_map('intval', (array) ($l['surveillants'] ?? []))));
            if (array_intersect($surveillants, $vus) !== []) {
                throw new InvalidArgumentException('Un surveillant ne tient qu\'une salle par épreuve.');
            }
            $vus = [...$vus, ...$surveillants];
            $sortie[(int) $l['salle_id']] = $surveillants;
        }
        if ($vus !== [] && $this->surveillantsPossibles()->whereKey($vus)->count() !== count($vus)) {
            throw new InvalidArgumentException('Surveillant inconnu : choisissez un compte de la plateforme.');
        }

        return $sortie;
    }

    /** @param  list<array{controle: string, epreuve: EpreuveExamen, detail: string, bloquant: bool}>  $conflits */
    private function resume(array $conflits): string
    {
        return implode(' ; ', array_map(fn (array $k): string => $k['epreuve']->libelle().' — '.$k['detail'], array_slice($conflits, 0, 4)));
    }

    private function autoriser(User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::EMPLOIS_DU_TEMPS), 403);
    }
}
