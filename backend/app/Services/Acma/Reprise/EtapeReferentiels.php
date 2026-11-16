<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;

/**
 * Étape 1 (§5.1, §5.3) : années reprises (2013-2014 à 2025-2026) et photographie annuelle des programmes en unités.
 * Aucune UE, ECUE ni maquette n'est créée ; les anomalies sont gardées et signalées. Un code de niveau inconnu bloque.
 */
final class EtapeReferentiels implements Etape
{
    public function nom(): string
    {
        return 'referentiels';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $annees = new EcritureArchive('acma_annees', 'code', $contexte->maintenant);
        $exclues = 0;
        foreach (LectureTransit::lignes('anneeacademic', ['anneeacademic', 'debutannee']) as $a) {
            if (! CorrespondancesAcma::anneeReprise($a->anneeacademic)) {
                $exclues++;

                continue;
            }
            $annees->ajouter(['code' => trim((string) $a->anneeacademic), 'statut_acma' => NormalisationAcma::texte($a->debutannee)]);
        }
        $comptesAnnees = $annees->terminer() + ['exclues' => $exclues];

        $ues = LectureTransit::table('ue')->get(['codue', 'libeleue'])->keyBy(fn (object $u): string => (string) $u->codue);
        $groupes = LectureTransit::table('grpue')->get(['codgrp', 'libelegrp', 'codniveau'])->keyBy(fn (object $g): string => (string) $g->codgrp);
        $unites = new EcritureArchive('acma_unites', 'id_prog_acma', $contexte->maintenant);
        $horsAnnee = $horsNorme = $autreNiveau = 0;
        foreach (LectureTransit::lignes('programe', ['idprog', 'anneeacademic', 'codniveau', 'codgrp', 'codue', 'semestre', 'pcc', 'ptp', 'pexam', 'coef', 'credit', 'cm', 'tp', 'semin', 'tpe', 'tge']) as $p) {
            if (! CorrespondancesAcma::anneeReprise($p->anneeacademic)) {
                $horsAnnee++;

                continue;
            }
            $codeNiveau = trim((string) $p->codniveau);
            $niveau = CorrespondancesAcma::niveau($codeNiveau);
            $ue = $ues[(string) $p->codue] ?? null;
            $groupe = $groupes[(string) $p->codgrp] ?? null;
            $intitule = NormalisationAcma::intitule($ue?->libeleue);
            $ponderations = [LectureTransit::nombre($p->pcc), LectureTransit::nombre($p->ptp), LectureTransit::nombre($p->pexam)];
            $nonNorme = abs(array_sum(array_map(fn (?float $x): float => (float) $x, $ponderations)) - 1) > 0.0001;
            $autre = $groupe !== null && trim((string) $groupe->codniveau) !== $codeNiveau;
            $horsNorme += (int) $nonNorme;
            $autreNiveau += (int) $autre;
            $unites->ajouter([
                'id_prog_acma' => (int) $p->idprog,
                'annee' => trim((string) $p->anneeacademic),
                'niveau_acma' => $codeNiveau,
                'niveau' => $niveau['niveau'],
                'parcours' => $niveau['parcours'],
                'code_ue' => (string) NormalisationAcma::texte($p->codue),
                'intitule_fr' => $intitule['fr'],
                'intitule_en' => $intitule['en'],
                'code_groupe' => NormalisationAcma::texte($p->codgrp),
                'libelle_groupe' => NormalisationAcma::texte($groupe?->libelegrp),
                'semestre_acma' => is_numeric(trim((string) $p->semestre)) ? (int) $p->semestre : null,
                'semestre' => NormalisationAcma::semestre($niveau['niveau'], $p->semestre),
                'credits' => self::entier($p->credit),
                'coefficient' => LectureTransit::nombre($p->coef),
                'pcc' => $ponderations[0],
                'ptp' => $ponderations[1],
                'pexam' => $ponderations[2],
                'heures' => json_encode(['cm' => self::entier($p->cm), 'tp' => self::entier($p->tp), 'seminaire' => self::entier($p->semin), 'tpe' => self::entier($p->tpe), 'tge' => self::entier($p->tge)], JSON_THROW_ON_ERROR),
                'ponderations_hors_norme' => $nonNorme,
                'groupe_autre_niveau' => $autre,
            ]);
        }

        return [
            'annees' => $comptesAnnees,
            'unites' => $unites->terminer() + ['hors_annees_reprises' => $horsAnnee, 'ponderations_hors_norme' => $horsNorme, 'groupe_autre_niveau' => $autreNiveau],
        ];
    }

    private static function entier(?string $v): ?int
    {
        $n = LectureTransit::nombre($v);

        return $n === null ? null : (int) round($n);
    }
}
