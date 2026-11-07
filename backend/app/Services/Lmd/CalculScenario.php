<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\AcquisUe;
use App\Models\Deliberation;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\InscriptionPedagogique;
use App\Models\MaquetteUe;
use App\Models\MesureJury;
use App\Models\RegleEvaluation;
use App\Models\ResultatScenario;
use App\Models\ResultatScenarioUe;
use App\Models\Scenario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcul d'un scénario de délibération (D35 ; port de `calculer_scenario`) :
 *
 * 1. UE de référence de chaque auditeur, notes de la session (lot 5), UE capitalisées ;
 * 2. application stricte des règles (seuil, note éliminatoire) ;
 * 3. mesures du jury dans leur ordre (relèvement, repêchage, cas particulier) ;
 * 4. synthèse : crédits, décision (D2), moyenne, MGP, rang par parcours, mention, félicitations.
 *
 * La note saisie n'est jamais touchée : `note_jury` est une valeur distincte.
 */
final class CalculScenario
{
    public function __construct(private readonly CalculNotes $notes) {}

    /** Population d'une délibération : inscriptions de l'année et du niveau (et du parcours) inscrites à des UE. */
    public static function population(Deliberation $d): Builder
    {
        return InscriptionAnnuelle::query()
            ->where('annee_academique', $d->annee_academique)
            ->where('niveau', $d->niveau)
            ->when($d->parcours_id !== null, fn (Builder $q) => $q->where('parcours_id', $d->parcours_id))
            ->whereHas('inscriptionsPedagogiques');
    }

    /** @return array{effectif: int, admis: int, autorises: int, ajournes: int} */
    public function calculer(Scenario $scenario): array
    {
        $d = $scenario->deliberation;
        $regle = RegleEvaluation::pourAnnee($d->annee_academique);
        $seuil = (float) $regle->seuil_validation;
        $opposable = (bool) ($regle->jury['note_eliminatoire_opposable'] ?? true);

        $inscriptions = self::population($d)
            ->with(['auditeur', 'parcours', 'inscriptionsPedagogiques' => fn ($q) => $q->with(['maquetteUe.ue', 'maquetteUe.ecues', 'notes', 'derogationRattrapage'])])
            ->get();
        $capitalises = $this->capitalises($inscriptions, $d);

        // 1-2. UE de référence, notes de la session, application stricte des règles
        $lignes = [];
        foreach ($inscriptions as $inscription) {
            foreach ($inscription->inscriptionsPedagogiques as $ip) {
                $lignes[] = $this->ligne($inscription, $ip, $regle, $d, $capitalises);
            }
        }

        // 3. Mesures du jury, dans l'ordre
        foreach ($scenario->mesures()->get() as $mesure) {
            $n = match ($mesure->type) {
                MesureJury::RELEVEMENT => $this->relever($lignes, $mesure, $opposable),
                MesureJury::REPECHAGE => $this->repecher($lignes, $mesure, $seuil, $opposable),
                default => $this->casParticulier($lignes, $mesure, $seuil),
            };
            $mesure->forceFill(['beneficiaires' => $n])->save();
        }

        // 4. Synthèse par auditeur
        $syntheses = $this->syntheses($inscriptions, $lignes, $regle, $scenario);

        DB::transaction(function () use ($scenario, $lignes, $syntheses): void {
            ResultatScenarioUe::query()->where('scenario_id', $scenario->id)->delete();
            ResultatScenario::query()->where('scenario_id', $scenario->id)->delete();
            foreach (array_chunk(array_map(fn (array $l): array => [...array_diff_key($l, ['_mesure_type' => 1, '_auditeur' => 1, '_parcours' => 1]), 'scenario_id' => $scenario->id], $lignes), 500) as $lot) {
                ResultatScenarioUe::query()->insert($lot);
            }
            foreach (array_chunk($syntheses, 500) as $lot) {
                ResultatScenario::query()->insert($lot);
            }
        });

        $stats = [
            'effectif' => count($syntheses),
            'admis' => count(array_filter($syntheses, fn (array $s): bool => $s['decision'] === 'AD')),
            'autorises' => count(array_filter($syntheses, fn (array $s): bool => $s['decision'] === 'AS')),
            'ajournes' => count(array_filter($syntheses, fn (array $s): bool => $s['decision'] === 'AJ')),
        ];
        $scenario->forceFill([...$stats, 'calcule_at' => now(),
            'taux_reussite' => $stats['effectif'] === 0 ? null : number_format(100 * ($stats['admis'] + $stats['autorises']) / $stats['effectif'], 2, '.', ''),
        ])->save();

        return $stats;
    }

    /**
     * Décision par les crédits (D2) : M1 tous les crédits = AD ; M2 tous les crédits = AD,
     * crédits écrits = AS ; sinon AJ.
     */
    public static function decision(string $niveau, int $credits, int $creditsEcrits, RegleEvaluation $regle): string
    {
        if ($credits >= $regle->credits_niveau) {
            return 'AD';
        }
        if ($niveau === 'M2' && $creditsEcrits >= $regle->credits_ecrits_terminal) {
            return 'AS';
        }

        return 'AJ';
    }

    /**
     * Acquis antérieurs (même UE, même niveau, année antérieure), par auditeur puis par UE.
     *
     * @param  Collection<int, InscriptionAnnuelle>  $inscriptions
     * @return array<int, array<int, AcquisUe>>
     */
    private function capitalises(Collection $inscriptions, Deliberation $d): array
    {
        $acquis = AcquisUe::query()
            ->whereIn('auditeur_id', $inscriptions->pluck('auditeur_id'))
            ->where('niveau', $d->niveau)
            ->where('annee_academique', '<', $d->annee_academique)
            ->orderBy('annee_academique')
            ->get();
        $parAuditeur = [];
        foreach ($acquis as $a) {
            $parAuditeur[$a->auditeur_id][$a->unite_enseignement_id] = $a;
        }

        return $parAuditeur;
    }

    /**
     * @param  array<int, array<int, AcquisUe>>  $capitalises
     * @return array<string, mixed>
     */
    private function ligne(InscriptionAnnuelle $inscription, InscriptionPedagogique $ip, RegleEvaluation $regle, Deliberation $d, array $capitalises): array
    {
        /** @var MaquetteUe $ue */
        $ue = $ip->maquetteUe;
        $acquis = $capitalises[$inscription->auditeur_id][$ue->unite_enseignement_id] ?? null;
        $normales = $ip->notes->where('session', EtapeSaisie::NORMALE)->keyBy('maquette_ecue_id')->all();
        $rattrapages = $d->session === EtapeSaisie::RATTRAPAGE ? $ip->notes->where('session', EtapeSaisie::RATTRAPAGE)->keyBy('maquette_ecue_id')->all() : [];
        $r = $this->notes->ue($ue, $regle, $normales, $rattrapages, $ip->derogationRattrapage !== null);
        $capitalisee = $acquis !== null || $ip->statut === InscriptionPedagogique::CAPITALISEE;
        $note = $r->note ?? ($capitalisee ? ($acquis?->note_retenue === null ? null : (float) $acquis->note_retenue) : null);

        return [
            'inscription_annuelle_id' => $inscription->id,
            'maquette_ue_id' => $ue->id,
            'credits' => $ue->credits,
            'partie' => $ue->partie,
            'note_normale' => self::dec($r->noteNormale),
            'note_retenue' => self::dec($r->note),
            'eliminatoire' => $r->eliminatoire,
            'capitalisee' => $capitalisee,
            'acquise_regle' => $capitalisee || $r->acquise,
            'note_jury' => self::dec($note),
            'acquise' => $capitalisee || $r->acquise,
            'mesure_id' => null,
            '_mesure_type' => null,
            '_auditeur' => $inscription->auditeur_id,
            '_parcours' => $inscription->parcours_id,
        ];
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function relever(array &$lignes, MesureJury $m, bool $opposable): int
    {
        $p = $m->parametres;
        $n = 0;
        foreach ($lignes as &$l) {
            $note = $l['note_retenue'] === null ? null : (float) $l['note_retenue'];
            if ($l['acquise'] || $note === null || $note < (float) $p['plage_min'] || $note > (float) $p['plage_max'] || ($opposable && $l['eliminatoire'])) {
                continue;
            }
            $this->appliquer($l, $m, (float) $p['cible']);
            $n++;
        }

        return $n;
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function repecher(array &$lignes, MesureJury $m, float $seuil, bool $opposable): int
    {
        $p = $m->parametres;
        $acquis = [];
        foreach ($lignes as $l) {
            $acquis[$l['inscription_annuelle_id']] = ($acquis[$l['inscription_annuelle_id']] ?? 0) + ($l['acquise'] ? $l['credits'] : 0);
        }
        $candidats = [];
        foreach ($lignes as $i => $l) {
            $note = $l['note_retenue'] === null ? null : (float) $l['note_retenue'];
            if ($l['acquise'] || $note === null || $note < $seuil - (float) $p['ecart_max'] || ($opposable && $l['eliminatoire'])
                || $acquis[$l['inscription_annuelle_id']] < (int) $p['credits_min']) {
                continue;
            }
            $candidats[$l['inscription_annuelle_id']][] = $i;
        }
        $n = 0;
        foreach ($candidats as $indices) {
            usort($indices, fn (int $a, int $b): int => [(float) $lignes[$b]['note_retenue'], $lignes[$a]['maquette_ue_id']] <=> [(float) $lignes[$a]['note_retenue'], $lignes[$b]['maquette_ue_id']]);
            foreach (array_slice($indices, 0, max(1, (int) ($p['ue_max'] ?? 1))) as $i) {
                $this->appliquer($lignes[$i], $m, $seuil);
                $n++;
            }
        }

        return $n;
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function casParticulier(array &$lignes, MesureJury $m, float $seuil): int
    {
        $p = $m->parametres;
        foreach ($lignes as &$l) {
            if ($l['inscription_annuelle_id'] === (int) $p['inscription_annuelle_id'] && $l['maquette_ue_id'] === (int) $p['maquette_ue_id'] && ! $l['acquise']) {
                $this->appliquer($l, $m, isset($p['note']) && $p['note'] !== '' ? (float) $p['note'] : $seuil);

                return 1;
            }
        }

        return 0;
    }

    /** @param  array<string, mixed>  $l */
    private function appliquer(array &$l, MesureJury $m, float $note): void
    {
        $l['note_jury'] = self::dec($note);
        $l['acquise'] = true;
        $l['mesure_id'] = $m->id;
        $l['_mesure_type'] = MesureJury::TYPES[$m->type].' ('.$m->motif.')';
    }

    /**
     * @param  Collection<int, InscriptionAnnuelle>  $inscriptions
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array<string, mixed>>
     */
    private function syntheses(Collection $inscriptions, array $lignes, RegleEvaluation $regle, Scenario $scenario): array
    {
        $parInscription = [];
        foreach ($lignes as $l) {
            $parInscription[$l['inscription_annuelle_id']][] = $l;
        }
        $seuilFelicitations = (float) (collect($regle->grille_mentions)->max('min') ?? 18);

        $syntheses = [];
        foreach ($inscriptions as $inscription) {
            $ls = $parInscription[$inscription->id] ?? [];
            $somme = fn (callable $filtre): int => array_sum(array_map(fn (array $l): int => $filtre($l) ? $l['credits'] : 0, $ls));
            $avecNote = array_filter($ls, fn (array $l): bool => $l['note_jury'] !== null);
            $creditsNotes = array_sum(array_column($avecNote, 'credits'));
            $moyenne = $creditsNotes > 0 ? array_sum(array_map(fn (array $l): float => (float) $l['note_jury'] * $l['credits'], $avecNote)) / $creditsNotes : null;
            $acquises = array_filter($ls, fn (array $l): bool => $l['acquise'] && $l['note_jury'] !== null);
            $creditsAcquisNotes = array_sum(array_column($acquises, 'credits'));
            $mgp = $creditsAcquisNotes > 0
                ? array_sum(array_map(fn (array $l): float => ($regle->grade((float) $l['note_jury'])['points'] ?? 0.0) * $l['credits'], $acquises)) / $creditsAcquisNotes
                : null;

            $creditsRegle = $somme(fn (array $l): bool => $l['acquise_regle']);
            $creditsAcquis = $somme(fn (array $l): bool => $l['acquise']);
            $decision = self::decision($inscription->niveau, $creditsAcquis, $somme(fn (array $l): bool => $l['acquise'] && $l['partie'] === 'ecrite'), $regle);
            $mention = in_array($decision, ['AD', 'AS'], true) && $moyenne !== null ? ($regle->grade($moyenne)['appreciation'] ?? null) : null;
            $mesures = array_values(array_unique(array_filter(array_column($ls, '_mesure_type'))));

            $syntheses[] = [
                'scenario_id' => $scenario->id,
                'inscription_annuelle_id' => $inscription->id,
                'credits_maquette' => $somme(fn (): bool => true),
                'credits_regle' => $creditsRegle,
                'credits_acquis' => $creditsAcquis,
                'credits_annee' => $somme(fn (array $l): bool => $l['acquise'] && ! $l['capitalisee']),
                'moyenne' => self::dec($moyenne),
                'mgp' => $mgp === null ? null : number_format($mgp, 2, '.', ''),
                'decision_calculee' => self::decision($inscription->niveau, $creditsRegle, $somme(fn (array $l): bool => $l['acquise_regle'] && $l['partie'] === 'ecrite'), $regle),
                'decision' => $decision,
                'rang' => null,
                'mention' => $mention,
                'felicitations_proposees' => $moyenne !== null && $moyenne >= $seuilFelicitations,
                'mesures' => $mesures === [] ? null : mb_substr(implode(' ; ', $mesures), 0, 1000),
                '_parcours' => $inscription->parcours_id,
            ];
        }

        // Rang par parcours, parmi les AD et AS, à la moyenne puis à la MGP (D14)
        $groupes = [];
        foreach ($syntheses as $i => $s) {
            if (in_array($s['decision'], ['AD', 'AS'], true)) {
                $groupes[(int) $s['_parcours']][] = $i;
            }
        }
        foreach ($groupes as $indices) {
            usort($indices, fn (int $a, int $b): int => [(float) $syntheses[$b]['moyenne'], (float) $syntheses[$b]['mgp']] <=> [(float) $syntheses[$a]['moyenne'], (float) $syntheses[$a]['mgp']]);
            $rang = 0;
            $precedent = null;
            foreach ($indices as $position => $i) {
                $cle = [(float) $syntheses[$i]['moyenne'], (float) $syntheses[$i]['mgp']];
                if ($cle !== $precedent) {
                    $rang = $position + 1;
                    $precedent = $cle;
                }
                $syntheses[$i]['rang'] = $rang;
            }
        }

        return array_map(fn (array $s): array => array_diff_key($s, ['_parcours' => 1]), $syntheses);
    }

    private static function dec(?float $v): ?string
    {
        return $v === null ? null : number_format($v, 3, '.', '');
    }
}
