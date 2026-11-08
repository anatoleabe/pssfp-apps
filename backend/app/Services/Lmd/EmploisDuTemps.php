<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\CalendrierAcademique;
use App\Models\EmploiDuTemps;
use App\Models\EvenementCalendrier;
use App\Models\JourNonOuvre;
use App\Models\MaquetteEcue;
use App\Models\Parcours;
use App\Models\PlageHoraire;
use App\Models\Salle;
use App\Models\Seance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Emplois du temps (spec lot 12c ; D21) : une période de cours du calendrier, un parcours ; séances
 * programmées (salle, plage, enseignant, nature), répétition hebdomadaire, report, publication.
 */
final class EmploisDuTemps
{
    public function __construct(private readonly ConflitsEmploiDuTemps $conflits) {}

    public function creer(EvenementCalendrier $periode, Parcours $parcours, User $auteur): EmploiDuTemps
    {
        $this->autoriser($auteur);
        $c = $periode->calendrier;
        if ($periode->type !== 'periode_cours' || ! $c->estSigne()) {
            throw new InvalidArgumentException('Un emploi du temps porte sur une période de cours d\'un calendrier signé.');
        }
        if ($periode->niveau !== null && $periode->niveau !== $parcours->niveau) {
            throw new InvalidArgumentException('La période concerne le '.$periode->niveau.' : choisissez un parcours de ce niveau.');
        }
        if (EmploiDuTemps::query()->where('periode_id', $periode->id)->where('parcours_id', $parcours->id)->exists()) {
            throw new InvalidArgumentException('Cet emploi du temps existe déjà.');
        }
        $e = new EmploiDuTemps;
        $e->forceFill(['periode_id' => $periode->id, 'parcours_id' => $parcours->id, 'cree_par' => $auteur->id])->save();

        return $e;
    }

    /** ECUE de la maquette validée du parcours pour l'année du calendrier. @return Collection<int, MaquetteEcue> */
    public function ecues(EmploiDuTemps $e): Collection
    {
        $annee = $e->periode->calendrier->annee_academique;

        return MaquetteEcue::query()
            ->whereHas('maquetteUe.maquette', fn ($q) => $q->where('statut', 'validee')->where('annee_academique', $annee)->where('parcours_id', $e->parcours_id))
            ->orderBy('code')->get();
    }

    /**
     * Programme une séance, répétée chaque semaine jusqu'à `$jusquau` le cas échéant (sans dépasser la période) ;
     * les jours non ouvrés, interruptions et examens du niveau sont sautés.
     *
     * @param  array<string, mixed>  $data
     * @return array{creees: int, sautees: list<string>}
     */
    public function programmer(EmploiDuTemps $e, array $data, User $auteur, ?string $jusquau = null): array
    {
        $this->autoriser($auteur);
        $champs = $this->champs($e, $data);
        $date = CarbonImmutable::parse((string) $data['date_seance']);
        $fin = $jusquau === null ? $date : CarbonImmutable::parse($jusquau)->min($e->periode->fin_prevue);
        $indisponibles = $jusquau === null ? collect() : $this->indisponibilites($e);
        $bilan = ['creees' => 0, 'sautees' => []];
        DB::transaction(function () use ($e, $champs, $date, $fin, $indisponibles, $auteur, &$bilan): void {
            for ($d = $date; $d->lte($fin); $d = $d->addWeek()) {
                $motif = $indisponibles->first(fn (array $i): bool => $d->betweenIncluded($i[0], $i[1]));
                if ($motif !== null) {
                    $bilan['sautees'][] = $d->format('d/m/Y').' ('.$motif[2].')';

                    continue;
                }
                (new Seance)->forceFill([...$champs, 'date_seance' => $d->toDateString(), 'emploi_du_temps_id' => $e->id, 'cree_par' => $auteur->id])->save();
                $bilan['creees']++;
            }
        });

        return $bilan;
    }

    /** @param  array<string, mixed>  $data */
    public function modifier(Seance $s, array $data, User $auteur): void
    {
        $this->autoriser($auteur);
        if ($s->statut !== 'prevue' || $s->emploiDuTemps === null) {
            throw new InvalidArgumentException('Seule une séance prévue d\'un emploi du temps se modifie.');
        }
        $champs = $this->champs($s->emploiDuTemps, $data);
        $s->forceFill([...$champs, 'date_seance' => CarbonImmutable::parse((string) $data['date_seance'])->toDateString()])->save();
    }

    /** Report (D21) : la séance passe à « reportée » et une séance de remplacement est créée, liée. @param array<string, mixed> $nouvelle */
    public function reporter(Seance $s, string $motif, array $nouvelle, User $auteur): Seance
    {
        $this->autoriser($auteur);
        if ($s->statut !== 'prevue' || $s->emploiDuTemps === null) {
            throw new InvalidArgumentException('Seule une séance prévue d\'un emploi du temps se reporte.');
        }
        if (mb_strlen(trim($motif)) < 5) {
            throw new InvalidArgumentException('Indiquez le motif du report.');
        }
        $data = [...$s->only(['maquette_ecue_id', 'salle_id', 'enseignant_id', 'nature', 'modalite']), ...$nouvelle];
        if (empty($nouvelle['plage_horaire_id']) && empty($nouvelle['debut'])) {
            $data['debut'] = $s->debut;
            $data['fin'] = $s->fin;
        }

        return DB::transaction(function () use ($s, $motif, $data, $auteur): Seance {
            $champs = $this->champs($s->emploiDuTemps, $data);
            $r = (new Seance)->forceFill([...$champs, 'date_seance' => CarbonImmutable::parse((string) $data['date_seance'])->toDateString(),
                'emploi_du_temps_id' => $s->emploi_du_temps_id, 'cree_par' => $auteur->id]);
            $r->save();
            $s->forceFill(['statut' => 'reportee', 'motif_report' => mb_substr(trim($motif), 0, 500), 'reportee_vers_id' => $r->id])->save();

            return $r;
        });
    }

    public function annuler(Seance $s, string $motif, User $auteur): void
    {
        $this->autoriser($auteur);
        if ($s->statut !== 'prevue') {
            throw new InvalidArgumentException('Seule une séance prévue s\'annule.');
        }
        if (mb_strlen(trim($motif)) < 5) {
            throw new InvalidArgumentException('Indiquez le motif de l\'annulation.');
        }
        $s->forceFill(['statut' => 'annulee', 'motif_annulation' => mb_substr(trim($motif), 0, 500)])->save();
    }

    /** Suppression d'une séance d'un brouillon (jamais pointée). */
    public function supprimer(Seance $s, User $auteur): void
    {
        $this->autoriser($auteur);
        if ($s->emploiDuTemps?->statut !== EmploiDuTemps::BROUILLON || $s->pointages()->exists()) {
            throw new InvalidArgumentException('Une séance ne se supprime que dans un brouillon ; sur un emploi du temps publié, annulez-la avec un motif.');
        }
        $s->delete();
    }

    public function publier(EmploiDuTemps $e, User $auteur): void
    {
        $this->autoriser($auteur);
        if ($e->statut !== EmploiDuTemps::BROUILLON) {
            throw new InvalidArgumentException('Emploi du temps déjà publié.');
        }
        if (! $e->seances()->exists()) {
            throw new InvalidArgumentException('Programmez au moins une séance avant de publier.');
        }
        $bloquants = array_values(array_filter($this->conflits->pour($e), fn (array $k): bool => $k['bloquant']));
        if ($bloquants !== []) {
            throw new InvalidArgumentException('Publication refusée : '.count($bloquants).' conflit(s). '
                .implode(' ; ', array_map(fn (array $k): string => $k['seance']->libelle().' : '.$k['detail'], array_slice($bloquants, 0, 4))));
        }
        $e->forceFill(['statut' => EmploiDuTemps::PUBLIE, 'publie_par' => $auteur->id, 'publie_at' => now()])->save();
    }

    /**
     * Volume horaire par ECUE : maquette (CM + TD + TP), programmé (prévu et tenu), tenu, reste.
     *
     * @return list<array{ecue: MaquetteEcue, maquette: float, programme: float, tenu: float, reste: float}>
     */
    public function volumes(EmploiDuTemps $e): array
    {
        $seances = $e->seances()->whereIn('statut', Seance::ACTIVES)->get()->groupBy('maquette_ecue_id');

        return $this->ecues($e)->map(function (MaquetteEcue $x) use ($seances): array {
            $s = $seances->get($x->id, collect());
            $maquette = (float) ($x->heures_cm + $x->heures_td + $x->heures_tp);
            $programme = round($s->sum(fn (Seance $y): int => $y->duree()) / 60, 1);

            return ['ecue' => $x, 'maquette' => $maquette, 'programme' => $programme,
                'tenu' => round($s->where('statut', 'tenue')->sum(fn (Seance $y): int => $y->duree()) / 60, 1),
                'reste' => max(0.0, $maquette - $programme)];
        })->all();
    }

    /** Avancement des périodes de cours (D21) : heures tenues / heures programmées des emplois du temps publiés. @return array<int, int> */
    public function avancementPeriodes(CalendrierAcademique $c): array
    {
        return Seance::query()->join('emplois_du_temps', 'emplois_du_temps.id', '=', 'seances.emploi_du_temps_id')
            ->join('evenements_calendrier', 'evenements_calendrier.id', '=', 'emplois_du_temps.periode_id')
            ->where('evenements_calendrier.calendrier_id', $c->id)->where('emplois_du_temps.statut', EmploiDuTemps::PUBLIE)
            ->whereIn('seances.statut', Seance::ACTIVES)
            ->get(['emplois_du_temps.periode_id', 'seances.statut', 'seances.debut', 'seances.fin'])
            ->groupBy('periode_id')
            ->map(function (Collection $s): int {
                $total = $s->sum(fn (Seance $y): int => $y->duree());

                return $total === 0 ? 0 : (int) round(100 * $s->where('statut', 'tenue')->sum(fn (Seance $y): int => $y->duree()) / $total);
            })->all();
    }

    /**
     * Champs validés d'une séance : ECUE de la maquette, heures (plage ou libres), salle active, nature, modalité.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function champs(EmploiDuTemps $e, array $data): array
    {
        if (! $this->ecues($e)->contains('id', (int) ($data['maquette_ecue_id'] ?? 0))) {
            throw new InvalidArgumentException('ECUE absent de la maquette validée de ce parcours.');
        }
        $plage = empty($data['plage_horaire_id']) ? null : PlageHoraire::query()->findOrFail($data['plage_horaire_id']);
        $debut = $plage?->heure_debut ?? (string) ($data['debut'] ?? '');
        $fin = $plage?->heure_fin ?? (string) ($data['fin'] ?? '');
        if ($debut === '' || $fin === '' || strcmp(substr($fin, 0, 5), substr($debut, 0, 5)) <= 0) {
            throw new InvalidArgumentException('Choisissez une plage horaire, ou une heure de fin après l\'heure de début.');
        }
        $salle = empty($data['salle_id']) ? null : Salle::query()->where('active', true)->findOrFail($data['salle_id']);
        $nature = $data['nature'] ?? null;
        if ($nature !== null && ! array_key_exists((string) $nature, Seance::NATURES)) {
            throw new InvalidArgumentException('Nature de séance inconnue.');
        }
        $modalite = (string) ($data['modalite'] ?? 'hybride');
        if (! array_key_exists($modalite, Seance::MODALITES)) {
            throw new InvalidArgumentException('Modalité inconnue.');
        }

        return [
            'maquette_ecue_id' => (int) $data['maquette_ecue_id'], 'debut' => substr($debut, 0, 5), 'fin' => substr($fin, 0, 5),
            'plage_horaire_id' => $plage?->id, 'salle_id' => $salle?->id, 'salle' => $salle?->libelle,
            'lien_reunion' => $salle?->nature === 'virtuelle' ? $salle->lien_reunion : ($data['lien_reunion'] ?? null),
            'enseignant_id' => empty($data['enseignant_id']) ? null : (int) $data['enseignant_id'], 'nature' => $nature, 'modalite' => $modalite,
        ];
    }

    /** Jours où une séance répétée est sautée : interruptions, examens du niveau, jours non ouvrés. @return Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}> */
    private function indisponibilites(EmploiDuTemps $e): Collection
    {
        $niveau = $e->parcours->niveau;
        $evenements = $e->periode->calendrier->evenements()->where('statut', '<>', 'annule')
            ->where(fn ($q) => $q->where('type', 'vacances')->orWhere(fn ($x) => $x->whereIn('type', ['examens', 'rattrapage'])
                ->where(fn ($n) => $n->whereNull('niveau')->orWhere('niveau', $niveau))))->get()
            ->map(fn (EvenementCalendrier $v): array => [$v->debut_prevu, $v->fin_prevue, $v->libelle]);
        $jours = JourNonOuvre::query()->whereBetween('jour', [$e->periode->debut_prevu, $e->periode->fin_prevue])->get()
            ->map(fn (JourNonOuvre $j): array => [$j->jour, $j->jour, $j->libelle]);

        return $evenements->concat($jours)->values();
    }

    private function autoriser(User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::EMPLOIS_DU_TEMPS), 403);
    }
}
