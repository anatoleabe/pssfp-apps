<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Jobs\SendThrottledMail;
use App\Mail\ProgrammationEnseignantMail;
use App\Models\EvenementCalendrier;
use App\Models\IndisponibiliteEnseignant;
use App\Models\MaquetteEcue;
use App\Models\ProgrammationEnseignant;
use App\Models\QuotaHoraire;
use App\Models\Seance;
use App\Models\User;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Programmation des enseignants (spec lot 12d ; D22) : quota horaire d'un ECUE par période de cours,
 * part confiée à chaque enseignant (proposée → validée → notifiée → acceptée ou déclinée),
 * indisponibilités, rattachement des séances de l'emploi du temps, charge et plafond.
 */
final class Programmations
{
    public const CLE_PLAFOND = 'lmd.charge_max_enseignant_periode';

    private const HEURES_MAX = 999;

    // ---------------------------------------------------------------- quotas

    /** ECUE des maquettes validées de l'année, des parcours du niveau de la période. @return Collection<int, MaquetteEcue> */
    public function ecuesPeriode(EvenementCalendrier $periode): Collection
    {
        $annee = $periode->calendrier->annee_academique;

        return MaquetteEcue::query()->with('maquetteUe.maquette.parcours')
            ->whereHas('maquetteUe.maquette', fn ($q) => $q->where('statut', 'validee')->where('annee_academique', $annee)
                ->when($periode->niveau !== null, fn ($m) => $m->whereHas('parcours', fn ($p) => $p->where('niveau', $periode->niveau))))
            ->orderBy('code')->get();
    }

    /**
     * Crée ou change le quota d'un ECUE pour la période. Un quota validé ne change qu'avec un motif (il passe à « modifié »).
     *
     * @param  array<string, mixed>  $heures
     */
    public function definirQuota(EvenementCalendrier $periode, MaquetteEcue $ecue, array $heures, User $auteur, ?string $motif = null): QuotaHoraire
    {
        $this->autoriser($auteur, PermissionsLmd::PROGRAMMATION);
        $this->verifierPeriode($periode);
        if (! $this->ecuesPeriode($periode)->contains('id', $ecue->id)) {
            throw new InvalidArgumentException('ECUE absent des maquettes validées de l\'année pour le niveau de la période.');
        }
        $h = $this->heures($heures);
        $q = QuotaHoraire::query()->where('periode_id', $periode->id)->where('maquette_ecue_id', $ecue->id)->first()
            ?? (new QuotaHoraire)->forceFill(['periode_id' => $periode->id, 'maquette_ecue_id' => $ecue->id]);
        if ($q->exists && $q->estValide()) {
            if (mb_strlen(trim((string) $motif)) < 5) {
                throw new InvalidArgumentException('Ce quota est validé : indiquez le motif de la modification.');
            }
            $q->forceFill(['statut' => QuotaHoraire::MODIFIE, 'motif_modification' => mb_substr(trim((string) $motif), 0, 500)]);
        }
        $q->forceFill([...$h, 'cree_par' => $q->cree_par ?? $auteur->id])->save();

        return $q;
    }

    public function validerQuota(QuotaHoraire $q, User $auteur): void
    {
        $this->autoriser($auteur, PermissionsLmd::PROGRAMMATION_VALIDER);
        if ($q->statut !== QuotaHoraire::PROPOSE) {
            throw new InvalidArgumentException('Ce quota est déjà validé.');
        }
        if ($q->total() <= 0) {
            throw new InvalidArgumentException('Un quota sans heures ne se valide pas.');
        }
        $q->forceFill(['statut' => QuotaHoraire::VALIDE, 'valide_par' => $auteur->id, 'valide_at' => now()])->save();
    }

    /**
     * Propose, pour chaque ECUE retenu sans quota dans la période, le volume de la maquette moins les quotas des autres périodes de l'année.
     *
     * @param  list<int>  $parcoursIds
     * @param  list<int>  $semestres
     * @return array{creees: int, epuises: list<string>}
     */
    public function proposerQuotas(EvenementCalendrier $periode, array $parcoursIds, array $semestres, User $auteur): array
    {
        $this->autoriser($auteur, PermissionsLmd::PROGRAMMATION);
        $this->verifierPeriode($periode);
        $ecues = $this->ecuesPeriode($periode)->filter(fn (MaquetteEcue $x): bool => in_array($x->maquetteUe->maquette->parcours_id, $parcoursIds, true)
            && in_array($x->maquetteUe->semestre, $semestres, true));
        $existants = QuotaHoraire::query()->whereIn('maquette_ecue_id', $ecues->pluck('id'))
            ->whereHas('periode', fn ($q) => $q->where('calendrier_id', $periode->calendrier_id))->get()->groupBy('maquette_ecue_id');
        $bilan = ['creees' => 0, 'epuises' => []];
        DB::transaction(function () use ($ecues, $existants, $periode, $auteur, &$bilan): void {
            foreach ($ecues as $x) {
                $autres = $existants->get($x->id, collect());
                if ($autres->contains('periode_id', $periode->id)) {
                    continue;
                }
                $reste = ['heures_cm' => max(0, $x->heures_cm - $autres->sum('heures_cm')), 'heures_td' => max(0, $x->heures_td - $autres->sum('heures_td')),
                    'heures_tp' => max(0, $x->heures_tp - $autres->sum('heures_tp'))];
                if (array_sum($reste) <= 0) {
                    $bilan['epuises'][] = $x->code;

                    continue;
                }
                (new QuotaHoraire)->forceFill([...$reste, 'periode_id' => $periode->id, 'maquette_ecue_id' => $x->id, 'cree_par' => $auteur->id])->save();
                $bilan['creees']++;
            }
        });

        return $bilan;
    }

    // ---------------------------------------------------------- programmations

    /** @param  array<string, mixed>  $data */
    public function programmer(QuotaHoraire $q, array $data, User $auteur): ProgrammationEnseignant
    {
        $this->autoriser($auteur, PermissionsLmd::PROGRAMMATION);
        $p = new ProgrammationEnseignant;
        $p->forceFill([...$this->champs($q, $data, null), 'quota_id' => $q->id, 'cree_par' => $auteur->id])->save();
        $this->rattacher($q->periode);

        return $p;
    }

    /** @param  array<string, mixed>  $data */
    public function modifier(ProgrammationEnseignant $p, array $data, User $auteur): void
    {
        $this->autoriser($auteur, PermissionsLmd::PROGRAMMATION);
        if (! in_array($p->statut, ProgrammationEnseignant::MODIFIABLES, true)) {
            throw new InvalidArgumentException('Une programmation notifiée ou acceptée ne se modifie plus : annulez-la, puis programmez de nouveau.');
        }
        $p->forceFill([...$this->champs($p->quota, $data, $p), 'statut' => ProgrammationEnseignant::PROPOSE,
            'motif' => null, 'valide_par' => null, 'valide_at' => null, 'repondu_at' => null])->save();
        $this->rattacher($p->quota->periode);
    }

    public function valider(ProgrammationEnseignant $p, User $auteur): void
    {
        $this->autoriser($auteur, PermissionsLmd::PROGRAMMATION_VALIDER);
        if ($p->statut !== ProgrammationEnseignant::PROPOSE) {
            throw new InvalidArgumentException('Seule une programmation proposée se valide.');
        }
        if (! $p->quota()->firstOrFail()->estValide()) {
            throw new InvalidArgumentException('Validez d\'abord le quota du cours.');
        }
        $p->forceFill(['statut' => ProgrammationEnseignant::VALIDE, 'valide_par' => $auteur->id, 'valide_at' => now()])->save();
    }

    /**
     * Notifie les programmations validées : un courriel par enseignant, qui récapitule ses programmations de la période.
     *
     * @param  iterable<ProgrammationEnseignant>  $programmations
     * @return array{notifiees: int, ignorees: int}
     */
    public function notifier(iterable $programmations, User $auteur): array
    {
        $this->autoriser($auteur, PermissionsLmd::PROGRAMMATION_VALIDER);
        $toutes = new EloquentCollection(collect($programmations)->all());
        $retenues = $toutes->filter(fn (ProgrammationEnseignant $p): bool => $p->statut === ProgrammationEnseignant::VALIDE)->values();
        $ignorees = $toutes->count() - $retenues->count();
        DB::transaction(function () use ($retenues): void {
            foreach ($retenues as $p) {
                $p->forceFill(['statut' => ProgrammationEnseignant::NOTIFIE, 'notifie_at' => now()])->save();
            }
            $retenues->loadMissing(['enseignant', 'quota.periode', 'quota.ecue.maquetteUe.maquette.parcours'])
                ->groupBy(fn (ProgrammationEnseignant $p): string => $p->enseignant_id.'-'.$p->quota->periode_id)
                ->each(fn (Collection $lot) => $this->envoyer($lot));
        });

        return ['notifiees' => $retenues->count(), 'ignorees' => $ignorees];
    }

    /** Réponse de l'enseignant à sa programmation notifiée. */
    public function repondre(ProgrammationEnseignant $p, bool $accepte, ?string $motif, User $enseignant): void
    {
        $this->autoriser($enseignant, PermissionsLmd::PROGRAMMATION_REPONDRE);
        abort_unless($p->enseignant_id === $enseignant->id, 403);
        if ($p->statut !== ProgrammationEnseignant::NOTIFIE) {
            throw new InvalidArgumentException('Seule une programmation notifiée attend votre réponse.');
        }
        if (! $accepte && mb_strlen(trim((string) $motif)) < 5) {
            throw new InvalidArgumentException('Indiquez le motif du refus.');
        }
        $p->forceFill(['statut' => $accepte ? ProgrammationEnseignant::ACCEPTE : ProgrammationEnseignant::DECLINE,
            'motif' => $accepte ? null : mb_substr(trim((string) $motif), 0, 500), 'repondu_at' => now()])->save();
        if (! $accepte) {
            $this->rattacher($p->quota->periode);
        }
    }

    public function annuler(ProgrammationEnseignant $p, string $motif, User $auteur): void
    {
        $this->autoriser($auteur, PermissionsLmd::PROGRAMMATION);
        if ($p->statut === ProgrammationEnseignant::ANNULE) {
            throw new InvalidArgumentException('Programmation déjà annulée.');
        }
        if (mb_strlen(trim($motif)) < 5) {
            throw new InvalidArgumentException('Indiquez le motif de l\'annulation.');
        }
        $p->forceFill(['statut' => ProgrammationEnseignant::ANNULE, 'motif' => mb_substr(trim($motif), 0, 500)])->save();
        $this->rattacher($p->quota->periode);
    }

    /**
     * Rattache chaque séance des emplois du temps de la période à la programmation active de son enseignant pour son ECUE (D22).
     *
     * @return int nombre de séances dont le rattachement a changé
     */
    public function rattacher(EvenementCalendrier $periode): int
    {
        $actives = ProgrammationEnseignant::query()->whereIn('statut', ProgrammationEnseignant::ACTIVES)
            ->whereHas('quota', fn ($q) => $q->where('periode_id', $periode->id))->with('quota:id,maquette_ecue_id')->get()
            ->keyBy(fn (ProgrammationEnseignant $p): string => $p->quota->maquette_ecue_id.'-'.$p->enseignant_id);
        $changees = 0;
        Seance::query()->whereHas('emploiDuTemps', fn ($q) => $q->where('periode_id', $periode->id))
            ->get(['id', 'maquette_ecue_id', 'enseignant_id', 'programmation_id'])
            ->each(function (Seance $s) use ($actives, &$changees): void {
                $cible = $s->enseignant_id === null ? null : $actives->get($s->maquette_ecue_id.'-'.$s->enseignant_id)?->id;
                if ($cible !== $s->programmation_id) {
                    Seance::query()->whereKey($s->id)->update(['programmation_id' => $cible]);
                    $changees++;
                }
            });

        return $changees;
    }

    // --------------------------------------------------------- indisponibilités

    public function declarerIndisponibilite(User $enseignant, string $debut, string $fin, ?string $motif, User $auteur): IndisponibiliteEnseignant
    {
        $this->autoriserPour($enseignant, $auteur);
        if (! $enseignant->hasRole('enseignant')) {
            throw new InvalidArgumentException('Une indisponibilité se déclare pour un compte qui a le rôle d\'enseignant.');
        }
        $d = CarbonImmutable::parse($debut, 'Africa/Douala');
        $f = CarbonImmutable::parse($fin, 'Africa/Douala');
        if ($f->lte($d)) {
            throw new InvalidArgumentException('La fin de l\'indisponibilité suit son début.');
        }
        $i = new IndisponibiliteEnseignant;
        // Eloquent écrit la date sans son fuseau : on la ramène au fuseau de l'application.
        $tz = (string) config('app.timezone');
        $i->forceFill(['enseignant_id' => $enseignant->id, 'debut' => $d->setTimezone($tz), 'fin' => $f->setTimezone($tz),
            'motif' => $motif === null || trim($motif) === '' ? null : mb_substr(trim($motif), 0, 255), 'cree_par' => $auteur->id])->save();

        return $i;
    }

    public function supprimerIndisponibilite(IndisponibiliteEnseignant $i, User $auteur): void
    {
        $this->autoriserPour($i->enseignant, $auteur);
        $i->delete();
    }

    // ---------------------------------------------------------------- suivi

    /**
     * Heures programmées (séances prévues et tenues) et tenues de chaque programmation, avec la première et la dernière séance.
     *
     * @param  Collection<int, ProgrammationEnseignant>  $programmations
     * @return array<int, array{programme: float, tenu: float, premiere: ?CarbonImmutable, derniere: ?CarbonImmutable}>
     */
    public function consommation(Collection $programmations): array
    {
        $seances = Seance::query()->whereIn('programmation_id', $programmations->pluck('id'))->whereIn('statut', Seance::ACTIVES)
            ->get(['programmation_id', 'statut', 'date_seance', 'debut', 'fin'])->groupBy('programmation_id');

        return $programmations->mapWithKeys(function (ProgrammationEnseignant $p) use ($seances): array {
            $s = $seances->get($p->id, collect());

            return [$p->id => [
                'programme' => round($s->sum(fn (Seance $x): int => $x->duree()) / 60, 1),
                'tenu' => round($s->where('statut', 'tenue')->sum(fn (Seance $x): int => $x->duree()) / 60, 1),
                'premiere' => $s->min('date_seance'), 'derniere' => $s->max('date_seance'),
            ]];
        })->all();
    }

    /**
     * Quotas de la période : quota, réparti, programmé, tenu, avancement (tenu / quota).
     *
     * @return list<array{quota: QuotaHoraire, reparti: float, programme: float, tenu: float, avancement: int}>
     */
    public function quotasPeriode(EvenementCalendrier $periode): array
    {
        $quotas = $periode->quotas()->with(['ecue.maquetteUe.maquette.parcours', 'programmations.enseignant'])->get()
            ->sortBy(fn (QuotaHoraire $q): string => $q->ecue->maquetteUe->maquette->parcours->code.' '.$q->ecue->code)->values()
            ->each(fn (QuotaHoraire $q) => $q->programmations->each->setRelation('quota', $q));
        $conso = $this->consommation($quotas->flatMap(fn (QuotaHoraire $q) => $q->programmations)->filter(fn (ProgrammationEnseignant $p): bool => $p->estActive())->values());

        return $quotas->map(function (QuotaHoraire $q) use ($conso): array {
            $actives = $q->programmations->filter(fn (ProgrammationEnseignant $p): bool => $p->estActive());
            $tenu = round($actives->sum(fn (ProgrammationEnseignant $p): float => $conso[$p->id]['tenu']), 1);

            return ['quota' => $q, 'reparti' => round($actives->sum(fn (ProgrammationEnseignant $p): float => $p->total()), 1),
                'programme' => round($actives->sum(fn (ProgrammationEnseignant $p): float => $conso[$p->id]['programme']), 1),
                'tenu' => $tenu, 'avancement' => $q->total() > 0 ? (int) round(100 * $tenu / $q->total()) : 0];
        })->all();
    }

    /**
     * Charge de chaque enseignant pour la période (programmations actives), face au plafond.
     *
     * @return list<array{enseignant: User, cours: int, allouees: float, programme: float, tenu: float, plafond: ?float}>
     */
    public function charges(EvenementCalendrier $periode): array
    {
        $progs = ProgrammationEnseignant::query()->whereIn('statut', ProgrammationEnseignant::ACTIVES)
            ->whereHas('quota', fn ($q) => $q->where('periode_id', $periode->id))->with(['enseignant', 'quota'])->get();
        $conso = $this->consommation($progs);
        $plafond = $this->plafond();

        return $progs->groupBy('enseignant_id')->map(fn (Collection $l): array => [
            'enseignant' => $l->first()->enseignant, 'cours' => $l->pluck('quota.maquette_ecue_id')->unique()->count(),
            'allouees' => round($l->sum(fn (ProgrammationEnseignant $p): float => $p->total()), 1),
            'programme' => round($l->sum(fn (ProgrammationEnseignant $p): float => $conso[$p->id]['programme']), 1),
            'tenu' => round($l->sum(fn (ProgrammationEnseignant $p): float => $conso[$p->id]['tenu']), 1),
            'plafond' => $plafond,
        ])->sortBy(fn (array $c): string => (string) $c['enseignant']->name)->values()->all();
    }

    /** Plafond de charge d'un enseignant par période, en heures ; null : pas de plafond. */
    public function plafond(): ?float
    {
        $v = AppSettings::get(self::CLE_PLAFOND);

        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    public function definirPlafond(?float $heures, User $auteur): void
    {
        $this->autoriser($auteur, PermissionsLmd::PROGRAMMATION_VALIDER);
        if ($heures !== null && ($heures <= 0 || $heures > self::HEURES_MAX)) {
            throw new InvalidArgumentException('Le plafond est un nombre d\'heures positif.');
        }
        AppSettings::set(self::CLE_PLAFOND, $heures, $auteur->id);
    }

    /**
     * Sous-barres du Gantt (D22) : une par programmation active, sous chaque période de cours du calendrier.
     *
     * @return array<int, list<array{libelle: string, debut: CarbonImmutable, fin: CarbonImmutable, avancement: int, statut: string, heures: string}>>
     */
    public function gantt(int $calendrierId): array
    {
        $progs = ProgrammationEnseignant::query()->whereIn('statut', ProgrammationEnseignant::ACTIVES)
            ->whereHas('quota.periode', fn ($q) => $q->where('calendrier_id', $calendrierId))
            ->with(['enseignant:id,name', 'quota.periode', 'quota.ecue:id,code'])->get();
        $conso = $this->consommation($progs);

        return $progs->sortBy(fn (ProgrammationEnseignant $p): string => $p->enseignant->name.' '.$p->quota->ecue->code)
            ->groupBy(fn (ProgrammationEnseignant $p): int => $p->quota->periode_id)
            ->map(fn (Collection $l): array => $l->map(fn (ProgrammationEnseignant $p): array => [
                'libelle' => $p->enseignant->name.' — '.$p->quota->ecue->code,
                'debut' => $p->debut_prevu ?? $conso[$p->id]['premiere'] ?? $p->quota->periode->debut_prevu,
                'fin' => $p->fin_prevue ?? $conso[$p->id]['derniere'] ?? $p->quota->periode->fin_prevue,
                'avancement' => $p->total() > 0 ? (int) min(100, round(100 * $conso[$p->id]['tenu'] / $p->total())) : 0,
                'statut' => $p->statut,
                'heures' => $conso[$p->id]['tenu'].' h tenues sur '.$p->total().' h allouées',
            ])->values()->all())->all();
    }

    // -------------------------------------------------------------- interne

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function champs(QuotaHoraire $q, array $data, ?ProgrammationEnseignant $actuelle): array
    {
        $enseignant = User::query()->find((int) ($data['enseignant_id'] ?? 0));
        if ($enseignant === null || ! $enseignant->hasRole('enseignant')) {
            throw new InvalidArgumentException('Choisissez un compte qui a le rôle d\'enseignant.');
        }
        $role = (string) ($data['role'] ?? 'intervenant');
        $modalite = (string) ($data['modalite'] ?? 'hybride');
        if (! array_key_exists($role, ProgrammationEnseignant::ROLES) || ! array_key_exists($modalite, Seance::MODALITES)) {
            throw new InvalidArgumentException('Rôle ou modalité inconnus.');
        }
        $autres = $q->programmations()->whereIn('statut', ProgrammationEnseignant::ACTIVES)->when($actuelle !== null, fn ($x) => $x->whereKeyNot($actuelle->id));
        if ((clone $autres)->where('enseignant_id', $enseignant->id)->exists()) {
            throw new InvalidArgumentException($enseignant->name.' est déjà programmé sur ce cours pour la période.');
        }
        if ($role === 'responsable' && (clone $autres)->where('role', 'responsable')->exists()) {
            throw new InvalidArgumentException('Ce cours a déjà un responsable pour la période.');
        }
        $debut = empty($data['debut_prevu']) ? null : CarbonImmutable::parse((string) $data['debut_prevu']);
        $fin = empty($data['fin_prevue']) ? null : CarbonImmutable::parse((string) $data['fin_prevue']);
        if ($debut !== null && $fin !== null && $fin->lt($debut)) {
            throw new InvalidArgumentException('La fin de la fenêtre d\'intervention suit son début.');
        }

        return [...$this->heures($data), 'enseignant_id' => $enseignant->id, 'role' => $role, 'modalite' => $modalite,
            'debut_prevu' => $debut?->toDateString(), 'fin_prevue' => $fin?->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{heures_cm: float, heures_td: float, heures_tp: float}
     */
    private function heures(array $data): array
    {
        $h = [];
        foreach (['heures_cm', 'heures_td', 'heures_tp'] as $k) {
            $v = $data[$k] ?? 0;
            if (! is_numeric($v === '' || $v === null ? 0 : $v)) {
                throw new InvalidArgumentException('Les heures sont des nombres.');
            }
            $f = (float) ($v ?? 0);
            if ($f < 0 || $f > self::HEURES_MAX || fmod($f * 2, 1.0) !== 0.0) {
                throw new InvalidArgumentException('Les heures sont positives, au demi-point près.');
            }
            $h[$k] = $f;
        }
        if (array_sum($h) <= 0) {
            throw new InvalidArgumentException('Indiquez au moins une heure de CM, de TD ou de TP.');
        }

        return $h;
    }

    /** @param  Collection<int, ProgrammationEnseignant>  $lot  programmations d'un enseignant pour une période */
    private function envoyer(Collection $lot): void
    {
        $e = $lot->first()->enseignant;
        if (blank($e->email)) {
            return;
        }
        $periode = $lot->first()->quota->periode;
        $lignes = $lot->map(fn (ProgrammationEnseignant $p): array => [
            'cours' => $p->quota->ecue->code.' — '.$p->quota->ecue->intitule.' ('.$p->quota->ecue->maquetteUe->maquette->parcours->code.')',
            'role' => ProgrammationEnseignant::ROLES[$p->role],
            'heures' => 'CM '.$p->heures_cm.' h, TD '.$p->heures_td.' h, TP '.$p->heures_tp.' h (total '.$p->total().' h)',
            'fenetre' => $p->fenetre() ?? 'sur toute la période',
            'modalite' => Seance::MODALITES[$p->modalite],
        ])->values()->all();
        SendThrottledMail::dispatch([(string) $e->email], new ProgrammationEnseignantMail(
            (string) $e->name, $periode->libelle, 'du '.$periode->debut_prevu->format('d/m/Y').' au '.$periode->fin_prevue->format('d/m/Y'),
            $lignes, url('/admin/programmations-enseignant'),
        ));
    }

    private function verifierPeriode(EvenementCalendrier $periode): void
    {
        if ($periode->type !== 'periode_cours' || ! $periode->calendrier->estSigne()) {
            throw new InvalidArgumentException('Un quota porte sur une période de cours d\'un calendrier signé.');
        }
    }

    private function autoriser(User $u, string $permission): void
    {
        abort_unless($u->can($permission), 403);
    }

    /** La Scolarité gère les indisponibilités de tous ; l'enseignant, les siennes. */
    private function autoriserPour(User $enseignant, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::PROGRAMMATION)
            || ($auteur->id === $enseignant->id && $auteur->can(PermissionsLmd::PROGRAMMATION_REPONDRE)), 403);
    }
}
