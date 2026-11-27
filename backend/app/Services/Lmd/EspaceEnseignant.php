<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\EmploiDuTemps;
use App\Models\EpreuveExamen;
use App\Models\EtatPaie;
use App\Models\LigneEtatPaie;
use App\Models\MaquetteEcue;
use App\Models\PlanningExamens;
use App\Models\PointageEnseignant;
use App\Models\ProgrammationEnseignant;
use App\Models\Salle;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ce que l'enseignant lit de sa propre activité (lot 29 ; étape 6 de l'audit LMD) : ses séances, ses surveillances,
 * ses heures et leur mise en paie. Lecture seule ; toute requête est bornée au compte passé en argument.
 */
final class EspaceEnseignant
{
    public const FUSEAU = 'Africa/Douala';

    /** Profondeur des séances passées affichées, en jours. */
    public const JOURS_PASSES = 30;

    public const LIMITE = 100;

    /** États de paie montrés à l'enseignant : ceux dont les montants sont arrêtés (ni brouillon, ni rejeté). */
    public const ETATS_MONTRES = [EtatPaie::VERIFIE, EtatPaie::VISE, EtatPaie::TRANSMIS, EtatPaie::PRIS_EN_CHARGE, EtatPaie::PAYE];

    public const EMARGEMENTS = [
        'a_emarger' => 'À émarger', PointageEnseignant::A_VALIDER => 'Émargé, à valider',
        PointageEnseignant::VALIDE => 'Validé', PointageEnseignant::REJETE => 'Rejeté',
    ];

    public function __construct(private readonly PointagesEnseignant $pointages) {}

    /**
     * Le compte a-t-il un espace d'enseignant ? Le droit d'émarger le donne ; un compte qui gère aussi les pointages ne
     * l'a que s'il enseigne lui-même (spec R3).
     */
    public static function concerne(?User $u): bool
    {
        if ($u === null || ! $u->can(PermissionsLmd::EMARGER)) {
            return false;
        }
        if (! $u->can(PermissionsLmd::CONSULTER) && ! $u->can(PermissionsLmd::POINTAGE)) {
            return true;
        }

        return Seance::query()->where('enseignant_id', $u->id)->exists()
            || ProgrammationEnseignant::query()->where('enseignant_id', $u->id)->exists();
    }

    /** @return Collection<int, Seance> séances du jour et à venir, annulées et reportées comprises */
    public function aVenir(User $enseignant): Collection
    {
        return $this->seances($enseignant)->whereDate('date_seance', '>=', $this->aujourdhui())
            ->orderBy('date_seance')->orderBy('debut')->limit(self::LIMITE)->get();
    }

    /** @return Collection<int, Seance> séances actives des derniers jours, avec les émargements du compte */
    public function recentes(User $enseignant): Collection
    {
        $debut = now(self::FUSEAU)->subDays(self::JOURS_PASSES)->toDateString();

        return $this->seances($enseignant)->whereIn('statut', Seance::ACTIVES)
            ->whereDate('date_seance', '<', $this->aujourdhui())->whereDate('date_seance', '>=', $debut)
            ->with(['pointagesEnseignant' => fn ($q) => $q->where('enseignant_id', $enseignant->id)])
            ->orderByDesc('date_seance')->orderByDesc('debut')->limit(self::LIMITE)->get();
    }

    /** État de l'émargement du compte pour une séance chargée par recentes() : clé de EMARGEMENTS. */
    public static function emargement(Seance $s): string
    {
        return $s->pointagesEnseignant->first()?->statut ?? 'a_emarger';
    }

    /**
     * Épreuves à venir d'un planning publié que le compte surveille, avec sa salle.
     *
     * @return list<array{epreuve: EpreuveExamen, salle: ?Salle}>
     */
    public function surveillances(User $surveillant): array
    {
        $epreuves = EpreuveExamen::query()->with(['planning.session', 'salles'])
            ->whereHas('planning', fn (Builder $q) => $q->where('statut', PlanningExamens::PUBLIE))
            ->whereHas('surveillants', fn (Builder $q) => $q->whereKey($surveillant->id))
            ->with(['surveillants' => fn ($q) => $q->whereKey($surveillant->id)])
            ->whereDate('date_epreuve', '>=', $this->aujourdhui())
            ->orderBy('date_epreuve')->orderBy('debut')->limit(self::LIMITE)->get();

        return $epreuves->map(fn (EpreuveExamen $e): array => [
            'epreuve' => $e,
            'salle' => $e->salles->firstWhere('id', $e->surveillants->first()?->pivot->salle_id),
        ])->all();
    }

    /**
     * Heures du compte par période et par cours : allouées (programmations actives), programmées (emploi du temps
     * publié), validées, à valider, émargements rejetés, heures portées sur un état de paie.
     *
     * @return list<array{periode: string, ecue: MaquetteEcue, alloue: float, programme: float, valide: float, a_valider: float, rejetes: int, en_paie: float}>
     */
    public function heures(User $enseignant): array
    {
        $lignes = [];
        $ligne = function (int $periodeId, ?string $periode, MaquetteEcue $ecue) use (&$lignes): string {
            $cle = $periodeId.'-'.$ecue->id;
            $lignes[$cle] ??= ['periode' => (string) $periode, 'ecue' => $ecue, 'alloue' => 0.0, 'programme' => 0.0, 'valide' => 0.0,
                'a_valider' => 0.0, 'rejetes' => 0, 'en_paie' => 0.0];

            return $cle;
        };

        $programmations = ProgrammationEnseignant::query()->with(['quota.ecue', 'quota.periode'])
            ->where('enseignant_id', $enseignant->id)->whereIn('statut', ProgrammationEnseignant::ACTIVES)->get();
        foreach ($programmations as $p) {
            if ($p->quota?->ecue === null) {
                continue;
            }
            $lignes[$ligne((int) $p->quota->periode_id, $p->quota->periode?->libelle, $p->quota->ecue)]['alloue'] += $p->total();
        }

        $seances = $this->seances($enseignant)->whereIn('statut', Seance::ACTIVES)->get();
        foreach ($seances as $s) {
            if ($s->ecue === null) {
                continue;
            }
            $lignes[$ligne((int) $s->emploiDuTemps->periode_id, $s->emploiDuTemps->periode?->libelle, $s->ecue)]['programme'] += $s->duree() / 60;
        }

        $pointages = PointageEnseignant::query()->with(['seance.ecue', 'seance.emploiDuTemps.periode'])
            ->where('enseignant_id', $enseignant->id)->get();
        foreach ($pointages as $p) {
            if ($p->seance?->ecue === null || $p->seance->emploiDuTemps === null) {
                continue;
            }
            $cle = $ligne((int) $p->seance->emploiDuTemps->periode_id, $p->seance->emploiDuTemps->periode?->libelle, $p->seance->ecue);
            if ($p->statut === PointageEnseignant::VALIDE) {
                $lignes[$cle]['valide'] += (int) $p->minutes_payables / 60;
                $lignes[$cle]['en_paie'] += $p->estPaye() ? (int) $p->minutes_payables / 60 : 0;
            } elseif ($p->statut === PointageEnseignant::A_VALIDER) {
                $lignes[$cle]['a_valider'] += $this->pointages->minutesPayables($p) / 60;
            } elseif ($p->statut === PointageEnseignant::REJETE) {
                $lignes[$cle]['rejetes']++;
            }
        }

        $sortie = array_map(fn (array $l): array => [...$l, 'alloue' => round($l['alloue'], 2), 'programme' => round($l['programme'], 2),
            'valide' => round($l['valide'], 2), 'a_valider' => round($l['a_valider'], 2), 'en_paie' => round($l['en_paie'], 2)], array_values($lignes));
        usort($sortie, fn (array $a, array $b): int => [$a['periode'], $a['ecue']->code] <=> [$b['periode'], $b['ecue']->code]);

        return $sortie;
    }

    /**
     * Lignes de paie du compte, regroupées par état (du plus récent au plus ancien) ; brouillons et rejets exclus.
     *
     * @return list<array{etat: EtatPaie, heures: float, net: int}>
     */
    public function paie(User $enseignant): array
    {
        return LigneEtatPaie::query()->with('etat')->where('enseignant_id', $enseignant->id)
            ->whereHas('etat', fn (Builder $q) => $q->whereIn('statut', self::ETATS_MONTRES))->get()
            ->groupBy('etat_id')
            ->map(fn (Collection $l): array => [
                'etat' => $l->first()->etat,
                'heures' => round((float) $l->whereIn('nature', ['CM', 'TD', 'TP'])->sum('quantite'), 2),
                'net' => (int) $l->sum('montant_net'),
            ])
            ->sortByDesc(fn (array $l): string => $l['etat']->periode_du->toDateString().'-'.$l['etat']->id)->values()->all();
    }

    /** @return Builder<Seance> séances du compte dans un emploi du temps publié */
    private function seances(User $enseignant): Builder
    {
        return Seance::query()->with(['ecue', 'salleAffectee', 'emploiDuTemps.parcours', 'emploiDuTemps.periode', 'remplacement'])
            ->where('enseignant_id', $enseignant->id)
            ->whereHas('emploiDuTemps', fn (Builder $q) => $q->where('statut', EmploiDuTemps::PUBLIE));
    }

    private function aujourdhui(): string
    {
        return now(self::FUSEAU)->toDateString();
    }
}
