<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Deliberation;
use App\Models\EtatPaie;
use App\Models\InscriptionPedagogique;
use App\Models\LigneEtatPaie;
use App\Models\PointageEnseignant;
use App\Models\Requete;
use App\Models\RequeteEvenement;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Journaux;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lot 13c-2 (D38) : contrôle de gestion. Port de `v_realise_paie`, `v_cout_ue`, `v_cout_parcours`,
 * `v_tableau_bord_mensuel` et `v_dossiers_en_retard` (sql/25_schema_controle_gestion.sql). Lecture seule.
 */
final class ControleGestion
{
    public const CLE_DELAI_REQUETES = 'lmd.delai_requetes_jours';

    public const DELAI_REQUETES_DEFAUT = 30;

    /** Un état de paie est en retard 30 jours après la fin de sa période (D38). */
    public const DELAI_PAIE_JOURS = 30;

    /** Réalisé : états qui ont quitté la Scolarité (v_realise_paie). */
    public const ETATS_REALISES = [EtatPaie::TRANSMIS, EtatPaie::PRIS_EN_CHARGE, EtatPaie::PAYE];

    public const FUSEAU = 'Africa/Douala';

    public static function peutConsulter(?User $user): bool
    {
        return (bool) $user?->can(PermissionsLmd::CONTROLE_GESTION);
    }

    public function delaiRequetes(): int
    {
        return (int) AppSettings::get(self::CLE_DELAI_REQUETES, self::DELAI_REQUETES_DEFAUT);
    }

    public function definirDelaiRequetes(int $jours, User $auteur): void
    {
        abort_unless($auteur->can('settings.manage'), 403);
        if ($jours < 1 || $jours > 365) {
            throw new InvalidArgumentException('Le délai de traitement d\'une requête est compris entre 1 et 365 jours.');
        }
        $avant = $this->delaiRequetes();
        AppSettings::set(self::CLE_DELAI_REQUETES, $jours, $auteur->id);
        activity(Journaux::PARAMETRES)->causedBy($auteur)->event('delai_requetes_regle')
            ->withProperties(['avant' => $avant, 'apres' => $jours])->log('Délai de traitement des requêtes réglé');
    }

    /** @return list<string> années académiques des maquettes, la plus récente d'abord */
    public function annees(): array
    {
        return DB::table('maquettes')->distinct()->orderByDesc('annee_academique')->pluck('annee_academique')->all();
    }

    /**
     * Coûts par UE d'une année (v_cout_ue).
     *
     * @return list<array{maquette_ue_id: int, parcours_id: int, parcours: string, ue: string, heures_cm: float, cout_cm: int, cout_primes: int, cout_total: int, inscrits: int, cout_par_inscrit: ?int, cout_horaire: ?int}>
     */
    public function coutsUe(string $annee): array
    {
        $ues = DB::table('maquette_ues as mu')
            ->join('maquettes as m', 'm.id', '=', 'mu.maquette_id')
            ->join('unites_enseignement as ue', 'ue.id', '=', 'mu.unite_enseignement_id')
            ->join('parcours as p', 'p.id', '=', 'm.parcours_id')
            ->where('m.annee_academique', $annee)
            ->orderBy('p.ordre')->orderBy('mu.semestre')->orderBy('mu.ordre')
            ->get(['mu.id', 'p.id as parcours_id', 'p.libelle as parcours', 'ue.code', 'ue.intitule']);

        $couts = $this->realise()
            ->join('maquette_ecues as me', 'me.id', '=', 'lignes_etat_paie.maquette_ecue_id')
            ->whereIn('me.maquette_ue_id', $ues->pluck('id'))
            ->groupBy('me.maquette_ue_id')
            ->selectRaw("me.maquette_ue_id,
                coalesce(sum(lignes_etat_paie.quantite) filter (where lignes_etat_paie.nature = 'CM'), 0) as heures_cm,
                coalesce(sum(lignes_etat_paie.montant_brut) filter (where lignes_etat_paie.nature = 'CM'), 0) as cout_cm,
                coalesce(sum(lignes_etat_paie.montant_brut) filter (where lignes_etat_paie.nature = 'prime_animation'), 0) as cout_primes,
                coalesce(sum(lignes_etat_paie.montant_brut), 0) as cout_total")
            ->get()->keyBy('maquette_ue_id');

        $inscrits = InscriptionPedagogique::query()->where('statut', InscriptionPedagogique::INSCRIT)
            ->whereIn('maquette_ue_id', $ues->pluck('id'))->groupBy('maquette_ue_id')
            ->selectRaw('maquette_ue_id, count(*) as n')->pluck('n', 'maquette_ue_id');

        return $ues->map(function (object $u) use ($couts, $inscrits): array {
            $c = $couts->get($u->id);
            $heures = round((float) ($c->heures_cm ?? 0), 2);
            $total = (int) ($c->cout_total ?? 0);
            $n = (int) ($inscrits[$u->id] ?? 0);

            return [
                'maquette_ue_id' => (int) $u->id,
                'parcours_id' => (int) $u->parcours_id,
                'parcours' => (string) $u->parcours,
                'ue' => trim($u->code.' — '.$u->intitule),
                'heures_cm' => $heures,
                'cout_cm' => (int) ($c->cout_cm ?? 0),
                'cout_primes' => (int) ($c->cout_primes ?? 0),
                'cout_total' => $total,
                'inscrits' => $n,
                'cout_par_inscrit' => $n > 0 ? (int) round($total / $n) : null,
                'cout_horaire' => $heures > 0 ? (int) round($total / $heures) : null,
            ];
        })->values()->all();
    }

    /**
     * Coûts par parcours d'une année (v_cout_parcours), à partir des coûts par UE.
     *
     * @param  list<array<string, mixed>>  $coutsUe
     * @return list<array{parcours: string, ues: int, heures_cm: float, cout_cm: int, cout_primes: int, cout_total: int, auditeurs: int, cout_par_auditeur: ?int}>
     */
    public function coutsParcours(string $annee, array $coutsUe): array
    {
        $auditeurs = InscriptionPedagogique::query()
            ->join('maquette_ues as mu', 'mu.id', '=', 'inscriptions_pedagogiques.maquette_ue_id')
            ->join('maquettes as m', 'm.id', '=', 'mu.maquette_id')
            ->where('m.annee_academique', $annee)->where('inscriptions_pedagogiques.statut', InscriptionPedagogique::INSCRIT)
            ->groupBy('m.parcours_id')
            ->selectRaw('m.parcours_id, count(distinct inscriptions_pedagogiques.inscription_annuelle_id) as n')
            ->pluck('n', 'm.parcours_id');

        return collect($coutsUe)->groupBy('parcours_id')->map(function ($ues, $parcoursId) use ($auditeurs): array {
            $total = (int) $ues->sum('cout_total');
            $n = (int) ($auditeurs[$parcoursId] ?? 0);

            return [
                'parcours' => (string) $ues->first()['parcours'],
                'ues' => $ues->count(),
                'heures_cm' => round((float) $ues->sum('heures_cm'), 2),
                'cout_cm' => (int) $ues->sum('cout_cm'),
                'cout_primes' => (int) $ues->sum('cout_primes'),
                'cout_total' => $total,
                'auditeurs' => $n,
                'cout_par_auditeur' => $n > 0 ? (int) round($total / $n) : null,
            ];
        })->values()->all();
    }

    /** Réalisé de l'année non rattaché à un cours (surveillances) : dans le total, hors coûts par UE. */
    public function horsUe(string $annee): int
    {
        return (int) $this->realise()->whereNull('lignes_etat_paie.maquette_ecue_id')
            ->where('lignes_etat_paie.annee_academique', $annee)->sum('lignes_etat_paie.montant_brut');
    }

    /**
     * Tableau de bord des 12 derniers mois (v_tableau_bord_mensuel), du plus ancien au plus récent.
     *
     * @return list<array{mois: string, heures_validees: float, heures_cm_payees: float, paie_brute: int, retenues: int, enseignants_payes: int, requetes_deposees: int, requetes_cloturees: int, deliberations_publiees: int}>
     */
    public function tableauMensuel(?CarbonImmutable $aujourdhui = null): array
    {
        $fin = ($aujourdhui ?? CarbonImmutable::now(self::FUSEAU))->endOfMonth();
        $debut = $fin->subMonthsNoOverflow(11)->startOfMonth();
        $mois = [];
        for ($m = $debut; $m <= $fin; $m = $m->addMonthNoOverflow()) {
            $mois[$m->format('Y-m')] = ['mois' => $m->format('Y-m'), 'heures_validees' => 0.0, 'heures_cm_payees' => 0.0, 'paie_brute' => 0,
                'retenues' => 0, 'enseignants_payes' => [], 'requetes_deposees' => 0, 'requetes_cloturees' => 0, 'deliberations_publiees' => 0];
        }
        $cle = fn ($date): string => CarbonImmutable::parse($date)->setTimezone(self::FUSEAU)->format('Y-m');
        $bornes = [$debut->toDateString(), $fin->toDateString()];
        $instants = [$debut->utc(), $fin->utc()];

        PointageEnseignant::query()->join('seances as s', 's.id', '=', 'pointages_enseignant.seance_id')
            ->where('pointages_enseignant.statut', PointageEnseignant::VALIDE)->whereBetween('s.date_seance', $bornes)
            ->get(['s.date_seance', 'pointages_enseignant.minutes_payables'])
            ->each(function ($p) use (&$mois): void {
                $mois[substr((string) $p->date_seance, 0, 7)]['heures_validees'] += ((int) $p->minutes_payables) / 60;
            });

        $this->realise()->whereBetween('etats_paie.periode_au', $bornes)
            ->get(['etats_paie.periode_au', 'lignes_etat_paie.nature', 'lignes_etat_paie.quantite', 'lignes_etat_paie.montant_brut', 'lignes_etat_paie.montant_retenue', 'lignes_etat_paie.enseignant_id'])
            ->each(function ($l) use (&$mois): void {
                $k = substr((string) $l->periode_au, 0, 7);
                $mois[$k]['heures_cm_payees'] += $l->nature === 'CM' ? (float) $l->quantite : 0.0;
                $mois[$k]['paie_brute'] += (int) $l->montant_brut;
                $mois[$k]['retenues'] += (int) $l->montant_retenue;
                $mois[$k]['enseignants_payes'][$l->enseignant_id] = true;
            });

        Requete::query()->whereBetween('created_at', $instants)->pluck('created_at')
            ->each(function ($d) use (&$mois, $cle): void {
                $mois[$cle($d)]['requetes_deposees']++;
            });
        RequeteEvenement::query()->where('statut', 'cloturee')->whereBetween('created_at', $instants)->pluck('created_at')
            ->each(function ($d) use (&$mois, $cle): void {
                $mois[$cle($d)]['requetes_cloturees']++;
            });
        Deliberation::query()->whereBetween('publiee_at', $instants)->pluck('publiee_at')
            ->each(function ($d) use (&$mois, $cle): void {
                $mois[$cle($d)]['deliberations_publiees']++;
            });

        return array_values(array_map(fn (array $m): array => [...$m,
            'heures_validees' => round($m['heures_validees'], 1),
            'heures_cm_payees' => round($m['heures_cm_payees'], 2),
            'enseignants_payes' => count($m['enseignants_payes']),
        ], $mois));
    }

    /**
     * Dossiers en retard (v_dossiers_en_retard) : requêtes ouvertes au-delà du délai, états de paie non payés
     * 30 jours après leur période. Les plus en retard d'abord.
     *
     * @return list<array{dossier: string, numero: string, statut: string, ouvert_le: string, echeance: string, jours: int, responsable: ?string, etat_uuid: ?string}>
     */
    public function dossiersEnRetard(): array
    {
        $aujourdhui = CarbonImmutable::now(self::FUSEAU)->startOfDay();
        $delai = $this->delaiRequetes();
        $dossiers = [];

        foreach ($this->requetesEnRetard($aujourdhui, $delai)->with('agent:id,name')->get() as $r) {
            $ouverte = CarbonImmutable::parse($r->created_at)->setTimezone(self::FUSEAU)->startOfDay();
            $echeance = $ouverte->addDays($delai);
            $dossiers[] = ['dossier' => 'Requête', 'numero' => (string) $r->numero, 'statut' => Requete::STATUTS[$r->statut] ?? (string) $r->statut,
                'ouvert_le' => $ouverte->toDateString(), 'echeance' => $echeance->toDateString(), 'jours' => (int) $echeance->diffInDays($aujourdhui),
                'responsable' => $r->agent?->name, 'etat_uuid' => null];
        }
        foreach ($this->etatsEnRetard($aujourdhui)->get() as $e) {
            $echeance = CarbonImmutable::parse($e->periode_au)->addDays(self::DELAI_PAIE_JOURS);
            $dossiers[] = ['dossier' => 'État de paie', 'numero' => (string) $e->numero, 'statut' => EtatPaie::STATUTS[$e->statut] ?? (string) $e->statut,
                'ouvert_le' => CarbonImmutable::parse($e->created_at)->setTimezone(self::FUSEAU)->toDateString(), 'echeance' => $echeance->toDateString(),
                'jours' => (int) $echeance->diffInDays($aujourdhui), 'responsable' => null, 'etat_uuid' => (string) $e->uuid];
        }

        usort($dossiers, fn (array $a, array $b): int => $b['jours'] <=> $a['jours']);

        return $dossiers;
    }

    /** Pastille du menu : deux COUNT. */
    public function nombreEnRetard(): int
    {
        $aujourdhui = CarbonImmutable::now(self::FUSEAU)->startOfDay();

        return $this->requetesEnRetard($aujourdhui, $this->delaiRequetes())->count() + $this->etatsEnRetard($aujourdhui)->count();
    }

    /** @return Builder<Requete> */
    private function requetesEnRetard(CarbonImmutable $aujourdhui, int $delai): Builder
    {
        return Requete::query()->whereIn('statut', Requete::OUVERTS)->where('created_at', '<', $aujourdhui->subDays($delai)->utc());
    }

    /** @return Builder<EtatPaie> */
    private function etatsEnRetard(CarbonImmutable $aujourdhui): Builder
    {
        return EtatPaie::query()->whereNotIn('statut', [EtatPaie::PAYE, EtatPaie::REJETE])
            ->where('periode_au', '<', $aujourdhui->subDays(self::DELAI_PAIE_JOURS)->toDateString());
    }

    /** @return Builder<LigneEtatPaie> lignes du réalisé (v_realise_paie) */
    private function realise(): Builder
    {
        return LigneEtatPaie::query()->join('etats_paie', 'etats_paie.id', '=', 'lignes_etat_paie.etat_id')
            ->whereIn('etats_paie.statut', self::ETATS_REALISES);
    }
}
