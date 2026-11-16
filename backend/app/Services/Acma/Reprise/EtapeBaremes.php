<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\NormalisationAcma;

/**
 * Étape 7a (§5.4, §3.6) : barèmes d'archive, une ligne par année et niveau ACMA. Le dû de référence est `montantAregler`.
 * Les barèmes sont identiques pour toutes les catégories ; si ce n'était pas le cas, le plus fréquent est retenu et la
 * ligne est signalée (`heterogene`). Sert à l'affichage et au calcul des arriérés (15f), jamais au compte.
 */
final class EtapeBaremes implements Etape
{
    public function nom(): string
    {
        return 'baremes';
    }

    public function executer(ContexteReprise $contexte): array
    {
        $groupes = [];
        $horsAnnee = 0;
        foreach (LectureTransit::lignes('configurationdu', ['IDdroitC', 'anneaacademic', 'Categorie', 'montantAregler', 'niveau', 'tranche1', 'tranche2', 'tranche3', 'inscription', 'TenuSport']) as $b) {
            if (! CorrespondancesAcma::anneeReprise($b->anneaacademic)) {
                $horsAnnee++;

                continue;
            }
            $niveau = trim((string) $b->niveau);
            CorrespondancesAcma::niveau($niveau);
            $contexteMontant = 'barème '.trim((string) $b->anneaacademic).' '.$niveau;
            $composantes = [
                'du_total' => NormalisationAcma::montant($b->montantAregler, $contexteMontant) ?? 0,
                'inscription' => NormalisationAcma::montant($b->inscription, $contexteMontant),
                'tranche1' => NormalisationAcma::montant($b->tranche1, $contexteMontant),
                'tranche2' => NormalisationAcma::montant($b->tranche2, $contexteMontant),
                'tranche3' => NormalisationAcma::montant($b->tranche3, $contexteMontant),
                'tenue_sport' => NormalisationAcma::montant($b->TenuSport, $contexteMontant),
            ];
            $groupes[trim((string) $b->anneaacademic).'|'.$niveau][] = ['categorie' => trim((string) $b->Categorie), 'composantes' => $composantes];
        }

        $ecriture = new EcritureArchive('acma_baremes', 'cle_source', $contexte->maintenant);
        $heterogenes = $composantesDifferentes = 0;
        ksort($groupes);
        foreach ($groupes as $cle => $lignes) {
            [$annee, $niveau] = explode('|', (string) $cle);
            $frequences = [];
            foreach ($lignes as $l) {
                $empreinte = json_encode($l['composantes'], JSON_THROW_ON_ERROR);
                $frequences[$empreinte] = ($frequences[$empreinte] ?? 0) + 1;
            }
            arsort($frequences);
            // Hétérogène : le dû total diffère selon la catégorie. Des composantes différentes à total égal (tenue de
            // sport comptée sans être ventilée, §3.6) sont seulement comptées.
            $heterogene = count(array_unique(array_map(fn (array $l): int => $l['composantes']['du_total'], $lignes))) > 1;
            $heterogenes += (int) $heterogene;
            $composantesDifferentes += (int) (count($frequences) > 1);
            $categories = array_values(array_unique(array_column($lignes, 'categorie')));
            sort($categories);
            $ecriture->ajouter([
                'cle_source' => (string) $cle,
                'annee' => $annee,
                'niveau_acma' => $niveau,
                'niveau' => CorrespondancesAcma::niveau($niveau)['niveau'],
                ...json_decode((string) array_key_first($frequences), true),
                'categories' => json_encode($categories, JSON_THROW_ON_ERROR),
                'heterogene' => $heterogene,
            ]);
        }

        return ['baremes' => $ecriture->terminer(), 'hors_annees_reprises' => $horsAnnee, 'heterogenes' => $heterogenes, 'composantes_differentes' => $composantesDifferentes];
    }
}
