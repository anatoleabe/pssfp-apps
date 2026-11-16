<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;
use Illuminate\Support\Facades\DB;

/**
 * Étape 4 (§3.3, §7.2) : inscriptions datées jusqu'en 2025-2026, une par (dossier, année, niveau ACMA). Les lignes en
 * double sont réunies (identifiants ACMA gardés) ; deux niveaux la même année restent deux inscriptions, signalées.
 * Les valeurs d'ACMA sont gardées telles quelles ; les recalculs viennent à l'étape « resultats ».
 */
final class EtapeInscriptions implements Etape
{
    public function nom(): string
    {
        return 'inscriptions';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $dossiers = DB::table('acma_dossiers')->whereNull('retire_de_la_source_at')->pluck('id', 'matricule')->all();
        $ignorees = ['sans_annee_ni_niveau' => 0, 'annee_invalide' => 0, 'annee_non_reprise' => 0, 'dossier_exclu' => 0];
        $groupes = [];
        foreach (LectureTransit::lignes('inscription_academic', ['id_inscription', 'matricule', 'anneeacademic', 'niveau', 'moydunivo', 'admis', 'redouble', 'Fraiscolarite', 'NbreDette', 'Totalcredit', 'parcours']) as $i) {
            $niveauAcma = trim((string) $i->niveau);
            if (trim((string) $i->anneeacademic) === '' || $niveauAcma === '') {
                $ignorees['sans_annee_ni_niveau']++;

                continue;
            }
            $annee = NormalisationAcma::annee($i->anneeacademic);
            if ($annee === null) {
                $ignorees['annee_invalide']++;

                continue;
            }
            if (! CorrespondancesAcma::anneeReprise($annee)) {
                $ignorees['annee_non_reprise']++;

                continue;
            }
            $matricule = (string) NormalisationAcma::matricule($i->matricule);
            if (! isset($dossiers[$matricule])) {
                $ignorees['dossier_exclu']++;

                continue;
            }
            CorrespondancesAcma::niveau($niveauAcma);
            $groupes["{$matricule}|{$annee}|{$niveauAcma}"][] = $i;
        }

        $niveauxParAnnee = [];
        foreach (array_keys($groupes) as $cle) {
            [$matricule, $annee] = explode('|', (string) $cle);
            $niveauxParAnnee["{$matricule}|{$annee}"] = ($niveauxParAnnee["{$matricule}|{$annee}"] ?? 0) + 1;
        }

        $ecriture = new EcritureArchive('acma_inscriptions', 'cle_source', $contexte->maintenant, avecUuid: true);
        $doublons = 0;
        foreach ($groupes as $cle => $lignes) {
            [$matricule, $annee, $niveauAcma] = explode('|', (string) $cle);
            $doublons += count($lignes) - 1;
            // Ligne de référence : admise d'abord, puis la plus récente.
            usort($lignes, fn (object $a, object $b): int => [(string) $b->admis === '1', (int) $b->id_inscription] <=> [(string) $a->admis === '1', (int) $a->id_inscription]);
            $r = $lignes[0];
            $ids = array_map(fn (object $l): int => (int) $l->id_inscription, $lignes);
            sort($ids);
            $niveau = CorrespondancesAcma::niveau($niveauAcma);
            $ecriture->ajouter([
                'cle_source' => (string) $cle,
                'dossier_id' => (int) $dossiers[$matricule],
                'annee' => $annee,
                'niveau_acma' => $niveauAcma,
                'niveau' => $niveau['niveau'],
                'parcours' => $niveau['parcours'],
                'ids_acma' => json_encode($ids, JSON_THROW_ON_ERROR),
                'admis_acma' => self::booleen($r->admis),
                'moyenne_acma' => LectureTransit::nombre($r->moydunivo),
                'total_credits_acma' => self::entier($r->Totalcredit),
                'nb_dettes_acma' => self::entier($r->NbreDette),
                'frais_scolarite_acma' => LectureTransit::nombre($r->Fraiscolarite),
                'redoublant_acma' => self::booleen($r->redouble),
                'deux_niveaux_meme_annee' => $niveauxParAnnee["{$matricule}|{$annee}"] > 1,
                // Parcours tel qu'ACMA le note sur l'inscription (spec de la réinscription des anciens, §4.1).
                'parcours_source' => ($p = NormalisationAcma::texte($r->parcours)) === null ? null : mb_substr($p, 0, 100),
            ]);
        }

        return ['inscriptions' => $ecriture->terminer(), 'lignes_en_double_reunies' => $doublons, 'ignorees' => $ignorees];
    }

    private static function booleen(?string $v): ?bool
    {
        $t = trim((string) $v);

        return $t === '' ? null : $t === '1';
    }

    private static function entier(?string $v): ?int
    {
        $n = LectureTransit::nombre($v);

        return $n === null ? null : (int) round($n);
    }
}
