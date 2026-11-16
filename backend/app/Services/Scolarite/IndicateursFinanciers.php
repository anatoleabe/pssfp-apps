<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\CorrectionVersement;
use App\Models\DeblocageAcces;
use App\Models\ImputationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Indicateurs du tableau de bord et de l'analyse de l'espace Finances (spec module-4-scolarite-espace-financier §5 ;
 * sous-lots F2 et F3). Lecture seule, rien n'est stocké.
 *
 * Un seul calcul : chaque ligne est la `SituationFinanciere` de l'inscription, celle de sa fiche, et chaque catégorie est
 * celle de `LibelleSituation` (fiche, liste des auditeurs, listes du lot C). Les montants lisent tout l'argent imputé avec
 * la part à confirmer ; les états (à jour, en retard, soldé) et l'accès ne lisent que l'argent confirmé (§3, point 2).
 * Volumes du PSSFP (quelques centaines d'inscriptions) : les sommes se font sur les lignes chargées en une requête.
 */
final class IndicateursFinanciers
{
    public const PRISE_EN_CHARGE = 'prise_en_charge';

    public const AUTOFINANCE = 'autofinance';

    public const FINANCEMENTS = [self::AUTOFINANCE => 'Autofinancé', self::PRISE_EN_CHARGE => 'Pris en charge'];

    /** Classes d'ancienneté des impayés échus (§5.6), en jours de retard. */
    public const ANCIENNETES = [
        'non_echu' => 'Non échu', '1_30' => '1 à 30 jours', '31_60' => '31 à 60 jours', '61_90' => '61 à 90 jours',
        '91_180' => '91 à 180 jours', 'plus_180' => 'Plus de 180 jours',
    ];

    /** Regroupements proposés (ventilations du tableau de bord, lignes de l'analyse). */
    public const DIMENSIONS = [
        'promotion' => 'Promotion', 'niveau' => 'Niveau', 'parcours' => 'Parcours', 'annee' => 'Année académique',
        'financement' => 'Financement', 'statut' => 'Statut de l\'auditeur', 'situation' => 'Situation',
    ];

    public const MOIS = 12;

    /**
     * Une ligne par inscription du périmètre P : sa situation, celle de la fiche.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lignes(CriteresFinances $c): Collection
    {
        $inscriptions = $this->requete($c)
            ->with(['bareme', 'parcours:id,code,libelle', 'auditeur:id,candidature_id,statut', 'auditeur.candidature:id,campagne_id,nom,prenom,matricule,specialite,withdrawn_at',
                'auditeur.candidature.campagne:id,promotion_numero'])
            ->withSum('imputationsActives', 'montant')
            ->withSum('imputationsConfirmees', 'montant')
            ->orderBy('id')->get();
        $ids = $inscriptions->pluck('id')->all();
        $prisesEnCharge = array_flip([
            ...Versement::query()->whereIn('inscription_annuelle_id', $ids)->whereNull('annule_at')->where('payeur', Versement::PAYEUR_EMPLOYEUR)->pluck('inscription_annuelle_id')->all(),
            ...DeblocageAcces::query()->whereIn('inscription_annuelle_id', $ids)->whereNotNull('engagement_id')->pluck('inscription_annuelle_id')->all(),
        ]);
        $acces = array_flip(InscriptionAnnuelle::query()->whereKey($ids)->accesOuvert()->pluck('id')->all());
        $aujourdhui = InscriptionAnnuelle::aujourdhui();

        return $inscriptions
            ->map(fn (InscriptionAnnuelle $i): array => self::ligne($i, isset($prisesEnCharge[$i->id]), isset($acces[$i->id]), $aujourdhui))
            ->filter(fn (array $l): bool => $c->parcours === null || $l['parcours'] === $c->parcours)
            ->values();
    }

    /**
     * Indicateurs A1 à A13 et E1 à E9 (§5.2 et §5.3) sur des lignes.
     *
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @return array<string, int|float|null>
     */
    public function indicateurs(Collection $lignes, bool $avecAvance = true): array
    {
        $somme = fn (string $k): int => (int) $lignes->sum($k);
        $compte = fn (string $situation): int => $lignes->where('situation', $situation)->count();
        $du = $somme('du');
        $verse = $somme('verse');
        $aConfirmer = $somme('a_confirmer');
        $exigible = $somme('exigible');
        $taux = fn (int $n, int $d): ?float => $d === 0 ? null : round(100 * $n / $d, 1);

        return [
            'du_brut' => $somme('brut'),
            'remises' => $somme('remise'),
            'du' => $du,
            'verse' => $verse,
            'confirme' => $verse - $aConfirmer,
            'a_confirmer' => $aConfirmer,
            'reste' => $somme('reste'),
            'taux' => $taux($verse, $du),
            'taux_confirme' => $taux($verse - $aConfirmer, $du),
            'exigible' => $exigible,
            'impaye_echu' => $somme('impaye_echu'),
            'taux_exigible' => $taux((int) $lignes->sum(fn (array $l): int => min($l['verse'], $l['exigible'])), $exigible),
            'avance' => $avecAvance ? $this->avance($lignes->pluck('auditeur_id')->unique()->all()) : null,
            'inscrits' => $lignes->count(),
            'non_soldes' => $compte(LibelleSituation::NON_SOLDEE),
            'a_jour' => $compte(LibelleSituation::A_JOUR),
            'en_retard' => $compte(LibelleSituation::EN_RETARD),
            'soldes' => $compte(LibelleSituation::SOLDEE),
            'a_jour_ou_soldes' => $compte(LibelleSituation::A_JOUR) + $compte(LibelleSituation::SOLDEE),
            'faute_confirmation' => $lignes->where('faute_confirmation', true)->count(),
            'avec_a_confirmer' => $lignes->filter(fn (array $l): bool => $l['a_confirmer'] > 0)->count(),
            'acces_ouvert' => $lignes->where('acces', true)->count(),
        ];
    }

    /**
     * Ventilation par une ou deux dimensions (§5.4, analyse §6.1) : chaque groupe a ses indicateurs, recalculés sur ses
     * lignes (les taux ne se somment pas).
     *
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @param  list<string>  $dimensions
     * @return list<array{cles: list<string>, indicateurs: array<string, int|float|null>}>
     */
    public function ventilation(Collection $lignes, array $dimensions): array
    {
        $dimensions = array_values(array_intersect($dimensions, array_keys(self::DIMENSIONS)));
        if ($dimensions === []) {
            return [];
        }
        $groupes = $lignes->groupBy(fn (array $l): string => implode("\x1F", array_map(fn (string $d): string => (string) $l[$d], $dimensions)));

        return $groupes->sortKeys()->map(fn (Collection $g, string $cle): array => [
            'cles' => array_map(fn (string $d, string $v): string => self::libelleValeur($d, $v), $dimensions, explode("\x1F", $cle)),
            'indicateurs' => $this->indicateurs($g, false),
        ])->values()->all();
    }

    /**
     * Argent imputé par payeur des versements (§5.4) : tout l'argent et sa part confirmée.
     *
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @return array<string, array{verse: int, confirme: int}>
     */
    public function parPayeur(Collection $lignes): array
    {
        $resultat = array_map(fn (): array => ['verse' => 0, 'confirme' => 0], Versement::PAYEURS);
        ImputationVersement::query()->whereIn('inscription_annuelle_id', $lignes->pluck('inscription_id')->all())->whereNull('annule_at')
            ->with(['versement:id,payeur', 'versement.confirmation:id,versement_id'])->get(['id', 'versement_id', 'montant'])
            ->each(function (ImputationVersement $x) use (&$resultat): void {
                $payeur = (string) $x->versement?->payeur;
                $resultat[$payeur] ??= ['verse' => 0, 'confirme' => 0];
                $resultat[$payeur]['verse'] += (int) $x->montant;
                $resultat[$payeur]['confirme'] += $x->versement?->confirmation !== null ? (int) $x->montant : 0;
            });

        return $resultat;
    }

    /**
     * Évolution mensuelle sur les 12 derniers mois, mois de Yaoundé (§5.5), pour les versements désignant une inscription
     * du périmètre.
     *
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @return list<array{mois: string, encaisse: int, enregistre: int, annule: int, confirme: int, corrections: int, cumul: int}>
     */
    public function mensuel(Collection $lignes): array
    {
        $fin = CarbonImmutable::now(InscriptionAnnuelle::FUSEAU)->startOfMonth();
        $mois = [];
        for ($k = self::MOIS - 1; $k >= 0; $k--) {
            $mois[$fin->subMonths($k)->format('Y-m')] = ['encaisse' => 0, 'enregistre' => 0, 'annule' => 0, 'confirme' => 0, 'corrections' => 0];
        }
        $cle = fn (?\DateTimeInterface $d): ?string => $d === null ? null : CarbonImmutable::instance($d)->setTimezone(InscriptionAnnuelle::FUSEAU)->format('Y-m');
        $ajouter = function (?string $m, string $colonne, int $montant) use (&$mois): void {
            if ($m !== null && isset($mois[$m])) {
                $mois[$m][$colonne] += $montant;
            }
        };
        $versements = Versement::query()->whereIn('inscription_annuelle_id', $lignes->pluck('inscription_id')->all())
            ->with('confirmation:id,versement_id,confirme_at')->get(['id', 'montant', 'date_versement', 'created_at', 'annule_at']);
        foreach ($versements as $v) {
            if (! $v->estAnnule()) {
                $ajouter($v->date_versement->format('Y-m'), 'encaisse', (int) $v->montant);
            }
            $ajouter($cle($v->created_at), 'enregistre', (int) $v->montant);
            $ajouter($cle($v->annule_at), 'annule', (int) $v->montant);
            $ajouter($cle($v->confirmation?->confirme_at), 'confirme', (int) $v->montant);
        }
        CorrectionVersement::query()->whereIn('versement_annule_id', $versements->pluck('id')->all())->pluck('created_at')
            ->each(fn ($d) => $ajouter($cle($d), 'corrections', 1));

        $cumul = 0;
        $resultat = [];
        foreach ($mois as $m => $valeurs) {
            $cumul += $valeurs['encaisse'];
            $resultat[] = ['mois' => $m] + $valeurs + ['cumul' => $cumul];
        }

        return $resultat;
    }

    /**
     * Ancienneté des impayés échus (§5.6) : montant impayé et nombre d'auditeurs par classe ; un auditeur compte dans la
     * classe de son palier impayé le plus ancien.
     *
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @return array<string, array{montant: int, auditeurs: int}>
     */
    public function anciennete(Collection $lignes): array
    {
        $resultat = array_map(fn (): array => ['montant' => 0, 'auditeurs' => 0], self::ANCIENNETES);
        foreach ($lignes as $l) {
            foreach ($l['impayes'] as $classe => $montant) {
                $resultat[$classe]['montant'] += $montant;
            }
            if ($l['anciennete'] !== null) {
                $resultat[$l['anciennete']]['auditeurs']++;
            }
        }

        return $resultat;
    }

    /** @return list<string> années académiques des inscriptions, la plus récente d'abord */
    public static function annees(): array
    {
        return InscriptionAnnuelle::query()->distinct()->orderByDesc('annee_academique')->pluck('annee_academique')->all();
    }

    /** @return list<string> parcours connus, pour la liste de choix */
    public function parcoursConnus(CriteresFinances $c): array
    {
        return $this->lignes(CriteresFinances::depuis(['annee' => $c->annee, 'campagne_id' => $c->campagneId, 'inclure_retires' => $c->inclureRetires]))
            ->pluck('parcours')->unique()->sort()->values()->all();
    }

    public static function libelleValeur(string $dimension, string $valeur): string
    {
        return match ($dimension) {
            'promotion' => $valeur === '' ? 'Sans promotion' : $valeur.'e promotion',
            'financement' => self::FINANCEMENTS[$valeur] ?? $valeur,
            'statut' => Auditeur::STATUTS[$valeur] ?? $valeur,
            'situation' => LibelleSituation::LIBELLES[$valeur] ?? $valeur,
            'mois' => CarbonImmutable::parse($valeur.'-01')->locale('fr')->isoFormat('MMMM YYYY'),
            default => $valeur,
        };
    }

    /** @return Builder<InscriptionAnnuelle> */
    private function requete(CriteresFinances $c): Builder
    {
        return InscriptionAnnuelle::query()
            ->when($c->annee !== null, fn (Builder $q) => $q->where('annee_academique', $c->annee))
            ->when($c->niveau !== null, fn (Builder $q) => $q->where('niveau', $c->niveau))
            ->whereHas('auditeur', fn (Builder $a) => $a->whereIn('statut', $c->statuts)
                ->whereHas('candidature', fn (Builder $d) => $d
                    ->when($c->campagneId !== null, fn (Builder $q) => $q->where('campagne_id', $c->campagneId))
                    ->when(! $c->inclureRetires, fn (Builder $q) => $q->whereNull('withdrawn_at'))));
    }

    /** @param  list<int>  $auditeurs */
    private function avance(array $auditeurs): int
    {
        return (int) Auditeur::query()->whereKey($auditeurs)->avecAvance()->get()->sum(fn (Auditeur $a): int => max(0, (int) $a->getAttribute('avance')));
    }

    /** @return array<string, mixed> */
    private static function ligne(InscriptionAnnuelle $i, bool $priseEnCharge, bool $acces, CarbonImmutable $aujourdhui): array
    {
        $s = $i->situation();
        $c = $i->auditeur?->candidature;
        $situation = LibelleSituation::cle($i);
        // Droits d'inscription exigibles à la création de l'inscription (décision Q10) ; tranches à leur échéance.
        $creation = CarbonImmutable::instance($i->created_at ?? $aujourdhui)->setTimezone(InscriptionAnnuelle::FUSEAU)->startOfDay();
        $exigible = 0;
        $impayeEchu = 0;
        $impayes = [];
        $plusAncien = null;
        foreach ($s->paliers as $p) {
            $echeance = $p->cle === Palier::INSCRIPTION ? $creation : $p->echeance?->startOfDay();
            $echu = $echeance !== null && ($p->cle === Palier::INSCRIPTION ? $echeance->lessThanOrEqualTo($aujourdhui) : $echeance->lessThan($aujourdhui->startOfDay()));
            if ($echu) {
                $exigible += $p->du;
                $impayeEchu += $p->reste;
            }
            if ($p->reste <= 0) {
                continue;
            }
            $classe = $echu ? self::classe((int) $echeance->diffInDays($aujourdhui->startOfDay())) : 'non_echu';
            $impayes[$classe] = ($impayes[$classe] ?? 0) + $p->reste;
            if ($echu && ($plusAncien === null || $echeance->lessThan($plusAncien))) {
                $plusAncien = $echeance;
            }
        }
        $anciennete = $plusAncien !== null ? self::classe((int) $plusAncien->diffInDays($aujourdhui->startOfDay())) : ($impayes === [] ? null : 'non_echu');
        // Sur tout l'argent, l'auditeur serait à jour ou soldé : seul le passage à la scolarité manque (E7).
        $toutLArgentAJour = $s->paliers[0]->solde() && collect($s->paliers)->every(fn (Palier $p): bool => ! $p->enRetard);

        return [
            'inscription_id' => $i->id,
            'auditeur_id' => (int) $i->auditeur_id,
            'matricule' => (string) $c?->matricule,
            'nom' => (string) $c?->nom,
            'prenom' => (string) $c?->prenom,
            'promotion' => (string) ($c?->campagne?->promotion_numero ?? ''),
            'annee' => (string) $i->annee_academique,
            'niveau' => (string) $i->niveau,
            'parcours' => (string) ($i->parcours?->code ?? (filled($c?->specialite) ? $c->specialite : CriteresFinances::NON_RENSEIGNE)),
            'statut' => (string) $i->auditeur?->statut,
            'financement' => $priseEnCharge ? self::PRISE_EN_CHARGE : self::AUTOFINANCE,
            'situation' => $situation,
            'brut' => $i->montant_inscription + $i->montant_tranche1 + $i->montant_tranche2,
            'remise' => $s->remise,
            'du' => $s->duTotal,
            'verse' => $s->verse,
            'a_confirmer' => $s->aConfirmer,
            'reste' => $s->reste,
            'exigible' => $exigible,
            'impaye_echu' => $impayeEchu,
            'impayes' => $impayes,
            'anciennete' => $anciennete,
            'faute_confirmation' => in_array($situation, [LibelleSituation::NON_SOLDEE, LibelleSituation::EN_RETARD], true) && $toutLArgentAJour,
            'acces' => $acces,
        ];
    }

    private static function classe(int $jours): string
    {
        return match (true) {
            $jours <= 30 => '1_30',
            $jours <= 60 => '31_60',
            $jours <= 90 => '61_90',
            $jours <= 180 => '91_180',
            default => 'plus_180',
        };
    }
}
