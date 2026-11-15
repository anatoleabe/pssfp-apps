<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\ComparaisonIdentite;
use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;
use Illuminate\Support\Facades\DB;

/**
 * Étape 3 (§6.3, §6.4) : rapprochement des dossiers d'archive et des auditeurs de la plateforme.
 *
 * - R1 et R2 : lien automatique ; R3, R4 et R6 : à arbitrer, sans lien ; R5 : information.
 * - Un arbitrage (décision signée dans `acma_rapprochements`) est toujours conservé.
 * - Un lien automatique dont l'identité a changé repasse « à arbitrer » ; la raison est inscrite.
 * - La plateforme n'est que lue : la candidature n'est jamais modifiée.
 */
final class EtapeRapprochement implements Etape
{
    private const STATUT_ARBITRAGE = ['lie' => 'lie_arbitre', 'non_lie' => 'sans_auditeur', 'ecarte' => 'ecarte'];

    public function nom(): string
    {
        return 'rapprochement';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $plateforme = DB::table('auditeurs as a')->join('candidatures as c', 'c.id', '=', 'a.candidature_id')
            ->leftJoin('campagnes_candidature as k', 'k.id', '=', 'c.campagne_id')
            ->whereNotNull('c.matricule')->whereNull('c.deleted_at')->orderBy('a.id')
            ->get(['a.id', 'c.matricule', 'c.nom', 'c.prenom', 'c.epouse', 'c.date_naissance', 'k.promotion_numero']);
        $parMatricule = $parDate = [];
        foreach ($plateforme as $p) {
            $p->norme = (string) NormalisationAcma::matricule($p->matricule);
            $p->date = $p->date_naissance === null ? null : substr((string) $p->date_naissance, 0, 10);
            $p->jetons = NormalisationAcma::jetons($p->nom, $p->prenom, $p->epouse);
            $parMatricule['k'.$p->norme] = $p;
            if ($p->date !== null) {
                $parDate[$p->date][] = $p;
            }
        }
        $humaines = DB::table('acma_rapprochements')->whereNotNull('decide_par')->orderBy('id')->get()->keyBy('dossier_id');
        $automatiques = DB::table('acma_rapprochements')->whereNull('decide_par')->orderBy('id')->get()->keyBy('dossier_id');

        $comptes = ['dossiers' => 0, 'R1' => 0, 'R2' => 0, 'R3' => 0, 'R4' => 0, 'R6' => 0, 'sans_auditeur' => 0, 'arbitrages_conserves' => 0, 'rapprochements_ajoutes' => 0, 'dossiers_modifies' => 0, 'liens_remis_a_arbitrer' => 0];
        $dossiers = DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->orderBy('id')
            ->get(['id', 'matricule', 'nom_complet', 'date_naissance', 'date_naissance_source', 'auditeur_id', 'statut_rapprochement']);
        foreach ($dossiers as $d) {
            $comptes['dossiers']++;
            $humaine = $humaines[$d->id] ?? null;
            if ($humaine !== null) {
                $comptes['arbitrages_conserves']++;
                $this->poser($d, self::STATUT_ARBITRAGE[$humaine->decision], $humaine->decision === 'lie' ? (int) $humaine->auditeur_id : null, $contexte, $comptes);

                continue;
            }

            $dateArchive = $d->date_naissance === null ? null : substr((string) $d->date_naissance, 0, 10);
            $jetons = NormalisationAcma::jetons($d->nom_complet);
            [$cas, $propose, $score] = [null, null, null];
            $meme = $parMatricule['k'.$d->matricule] ?? null;
            if ($meme !== null) {
                $score = ComparaisonIdentite::score($jetons, $meme->jetons);
                $cas = ComparaisonIdentite::memeMatricule($dateArchive, $meme->date, $score);
                $propose = $meme;
            } elseif ($dateArchive !== null) {
                foreach ($parDate[$dateArchive] ?? [] as $p) {
                    $s = ComparaisonIdentite::score($jetons, $p->jetons);
                    if (ComparaisonIdentite::autreMatricule($dateArchive, $p->date, $s)) {
                        [$cas, $propose, $score] = ['R6', $p, $s];
                        break;
                    }
                }
            }

            if ($cas === null) {
                $comptes['sans_auditeur']++;
                $this->poser($d, 'sans_auditeur', null, $contexte, $comptes);

                continue;
            }
            $comptes[$cas]++;
            $lien = in_array($cas, ComparaisonIdentite::LIENS_AUTOMATIQUES, true);
            $remis = ! $lien && $d->statut_rapprochement === 'lie_auto';
            $comptes['liens_remis_a_arbitrer'] += (int) $remis;
            $precedent = $automatiques[$d->id] ?? null;
            if ($precedent === null || $precedent->cas !== $cas || (int) $precedent->auditeur_id !== (int) $propose->id) {
                DB::table('acma_rapprochements')->insert([
                    'dossier_id' => $d->id,
                    'auditeur_id' => $propose->id,
                    'cas' => $cas,
                    'score' => $score,
                    'decision' => $lien ? 'lie' : null,
                    'motif' => ($remis ? 'Identité ACMA modifiée depuis la reprise précédente. ' : '').ComparaisonIdentite::motif($cas),
                    'decide_par' => null,
                    'reprise_id' => $contexte->repriseId,
                    'created_at' => $contexte->maintenant,
                ]);
                $comptes['rapprochements_ajoutes']++;
            }
            $this->poser($d, $lien ? 'lie_auto' : 'a_arbitrer', $lien ? (int) $propose->id : null, $contexte, $comptes);
            if (! $lien) {
                $contexte->lister('a_arbitrer', [
                    'cas' => $cas, 'score' => $score,
                    'matricule_acma' => $d->matricule, 'nom_acma' => $d->nom_complet, 'naissance_acma' => $d->date_naissance_source,
                    'matricule_plateforme' => $propose->matricule, 'nom_plateforme' => trim($propose->nom.' '.$propose->prenom.' '.($propose->epouse ?? '')), 'naissance_plateforme' => $propose->date,
                ]);
            }
        }

        $this->listesDeLaPlateforme($plateforme, $parMatricule, $contexte, $comptes);

        return $comptes;
    }

    /** @param  array<string, int>  $comptes */
    private function poser(object $dossier, string $statut, ?int $auditeurId, ContexteReprise $contexte, array &$comptes): void
    {
        if ($dossier->statut_rapprochement === $statut && (int) $dossier->auditeur_id === (int) $auditeurId) {
            return;
        }
        DB::table('acma_dossiers')->where('id', $dossier->id)->update(['auditeur_id' => $auditeurId, 'statut_rapprochement' => $statut, 'updated_at' => $contexte->maintenant]);
        $comptes['dossiers_modifies']++;
    }

    /**
     * R5 (dans la plateforme, absent d'ACMA) et auditeurs actifs dans ACMA en 2026-2027 absents de la plateforme (§6.4).
     *
     * @param  iterable<object>  $plateforme
     * @param  array<string, object>  $parMatricule
     * @param  array<string, int>  $comptes
     */
    private function listesDeLaPlateforme(iterable $plateforme, array $parMatricule, ContexteReprise $contexte, array &$comptes): void
    {
        $acma = [];
        foreach (LectureTransit::table('etudiant')->get(['matricule', 'nometudiant']) as $e) {
            $acma['k'.NormalisationAcma::matricule($e->matricule)] = (string) $e->nometudiant;
        }
        $absents = $vingtCinq = $vingtCinqLies = 0;
        $lies = DB::table('acma_dossiers')->whereNotNull('auditeur_id')->whereNull('retire_de_la_source_at')->pluck('auditeur_id')->flip();
        foreach ($plateforme as $p) {
            if (str_starts_with($p->norme, '25')) {
                $vingtCinq++;
                $vingtCinqLies += (int) isset($lies[$p->id]);
            }
            if (! isset($acma['k'.$p->norme])) {
                $absents++;
                $contexte->lister('absents_acma', ['matricule' => $p->matricule, 'nom' => trim($p->nom.' '.$p->prenom), 'promotion' => $p->promotion_numero]);
            }
        }
        $horsPlateforme = 0;
        foreach (LectureTransit::table('inscription_academic')->where('anneeacademic', CorrespondancesAcma::ANNEE_EN_COURS)->get(['matricule', 'niveau']) as $i) {
            $norme = NormalisationAcma::matricule($i->matricule);
            if ($norme !== null && ! isset($parMatricule['k'.$norme])) {
                $horsPlateforme++;
                $contexte->lister('actifs_hors_plateforme', ['matricule' => (string) $i->matricule, 'nom' => $acma['k'.$norme] ?? null, 'niveau_2026_2027' => (string) $i->niveau]);
            }
        }
        $comptes['R5_absents_d_acma'] = $absents;
        $comptes['actifs_2026_2027_hors_plateforme'] = $horsPlateforme;
        $contexte->infos['rapprochement'] = ['absents_acma' => $absents, 'plateforme_25' => $vingtCinq, 'plateforme_25_lies' => $vingtCinqLies, 'actifs_hors_plateforme' => $horsPlateforme];
    }
}
