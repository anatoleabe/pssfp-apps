<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\ExtractionAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;

/**
 * Fiches d'ACMA regroupées par matricule normalisé, avec leur motif d'exclusion éventuel (§6.1) :
 * matricule invalide, filière de test, fiche `zz`, seulement 2026-2027, aucune inscription datée sans document (Q1).
 */
final class FichesAcma
{
    public const DOCUMENTS = ['pvsoutenance' => 'matricule', 'certificat' => 'Matricule', 'attestationr' => 'Matricule', 'misedispos' => 'Matricule', 'nonperceptionb' => 'Matricule', 'prisecours' => 'Matricule'];

    public const MOTIFS = [
        'matricule_invalide' => 'Matricule vide ou sans lettre ni chiffre',
        'filiere_test' => 'Filière de test (POURTEST)',
        'neutralisee_zz' => 'Fiche neutralisée dans ACMA (préfixe zz, Q2)',
        'seulement_2026_2027' => 'Inscrite seulement en 2026-2027 : relève de la plateforme',
        'sans_inscription_datee' => 'Aucune inscription datée, aucun document (Q1)',
    ];

    /**
     * @return array<string, array{matricule: string, fiches: list<object>, annees: list<string>, en_cours: bool, document: bool, exclusion: string|null}>
     *                                                                                                                                                     clé : 'k' + matricule normalisé ; les fiches sans matricule valide sont sous 'invalide:<n>'
     */
    public static function grouper(): array
    {
        $groupes = [];
        $n = 0;
        foreach (LectureTransit::lignes('etudiant', ExtractionAcma::COLONNES['etudiant']) as $f) {
            $norme = NormalisationAcma::matricule($f->matricule);
            $cle = $norme === null ? 'invalide:'.(++$n) : 'k'.$norme;
            $groupes[$cle] ??= ['matricule' => (string) $norme, 'fiches' => [], 'annees' => [], 'en_cours' => false, 'document' => false, 'exclusion' => null];
            $groupes[$cle]['fiches'][] = $f;
        }
        foreach (LectureTransit::lignes('inscription_academic', ['matricule', 'anneeacademic', 'niveau']) as $i) {
            $cle = 'k'.NormalisationAcma::matricule($i->matricule);
            if (! isset($groupes[$cle]) || trim((string) $i->niveau) === '') {
                continue;
            }
            $annee = NormalisationAcma::annee($i->anneeacademic);
            if (CorrespondancesAcma::anneeReprise($annee)) {
                $groupes[$cle]['annees'][$annee] = $annee;
            } elseif ($annee !== null && $annee > CorrespondancesAcma::DERNIERE_ANNEE_REPRISE) {
                $groupes[$cle]['en_cours'] = true;
            }
        }
        foreach (self::DOCUMENTS as $table => $colonne) {
            foreach (LectureTransit::table($table)->pluck($colonne) as $m) {
                $cle = 'k'.NormalisationAcma::matricule($m);
                if (isset($groupes[$cle])) {
                    $groupes[$cle]['document'] = true;
                }
            }
        }
        foreach ($groupes as $cle => $g) {
            $annees = array_values($g['annees']);
            sort($annees);
            $groupes[$cle]['annees'] = $annees;
            $groupes[$cle]['exclusion'] = self::exclusion($g['matricule'], $g['fiches'], $annees, $g['en_cours'], $g['document']);
        }

        return $groupes;
    }

    /**
     * @param  list<object>  $fiches
     * @param  list<string>  $annees
     */
    private static function exclusion(string $matricule, array $fiches, array $annees, bool $enCours, bool $document): ?string
    {
        if ($matricule === '') {
            return 'matricule_invalide';
        }
        foreach ($fiches as $f) {
            if (trim((string) $f->parcours) === CorrespondancesAcma::FILIERE_TEST) {
                return 'filiere_test';
            }
            if (NormalisationAcma::estNeutralise($f->matricule)) {
                return 'neutralisee_zz';
            }
        }
        if ($annees === [] && $enCours) {
            return 'seulement_2026_2027';
        }
        if ($annees === [] && ! $document) {
            return 'sans_inscription_datee';
        }

        return null;
    }
}
