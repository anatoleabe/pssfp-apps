<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Services\ResultatsSelection\TableauRepartitionWord as Tableau;
use App\Services\ResultatsSelection\WordGabarit as G;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Shared\Converter;

/**
 * Statistiques complémentaires du PV (ajout du 15 oct. 2026) : synthèse,
 * répartitions par statut actuel, âge, diplôme et domaine d'accès, et deux
 * graphiques Word natifs, modifiables dans Word.
 */
final class PvStatistiquesWord
{
    /** Prune de la charte, pour le graphique à une seule série. */
    private const PRUNE = '4A2E67';

    private const TITRE = ['bold' => true, 'underline' => 'single'];

    public function synthese(Section $section, ResultatsSelection $r): void
    {
        $p = $r->profil;
        Tableau::titre($section, 'SYNTHESE DES DOSSIERS RETENUS');

        $lignes = [
            ['Candidats retenus', $p->total.' (dont '.count($r->nationaux).' nationaux et '.count($r->etrangers).' étrangers)'],
            ['Femmes', $p->part($p->femmes)],
            ['Formation à distance', $p->part($p->distanciel)],
            ['Fonctionnaires titulaires', $p->part($p->fonctionnaires)],
            ['Ensemble du secteur public', $p->part($p->secteurPublic)],
            ['Âge moyen', $p->ageMoyen === null ? 'Non précisé'
                : $this->decimal($p->ageMoyen).' ans (de '.$p->ageMinimum.' à '.$p->ageMaximum.' ans)'],
            ['Première langue anglaise', $p->part($p->anglophones)],
        ];

        $libelle = 5200;
        $table = G::grille($section);
        G::enTete($table, [['INDICATEUR', $libelle], ['VALEUR', G::LARGEUR - $libelle]]);
        foreach ($lignes as [$indicateur, $valeur]) {
            G::ligne($table, [[$indicateur, $libelle], [$valeur, G::LARGEUR - $libelle, true]]);
        }
    }

    public function complements(Section $section, ResultatsSelection $r): void
    {
        $p = $r->profil;
        $section->addText('AUTRES REPARTITIONS DES DOSSIERS RETENUS', self::TITRE, ['spaceBefore' => 300, 'spaceAfter' => 100, 'keepNext' => true]);

        Tableau::parLignes($section, 'REPARTITION SELON LE STATUT ACTUEL', $p->parStatut, 'STATUT ACTUEL / SPECIALITE');
        $sigles = [];
        foreach ($p->parStatut->colonnes as $libelle => $sigle) {
            $sigles[] = $sigle.' : '.$libelle;
        }
        $section->addText(implode(' ; ', $sigles).'.', G::police(italic: true, size: 8), ['spaceBefore' => 60, 'spaceAfter' => 120]);

        Tableau::parLignes($section, 'REPARTITION PAR TRANCHE D’AGE', $p->parAge, 'TRANCHE D’AGE / GENRE');
        if ($p->ageMoyen !== null) {
            $section->addText('Âge moyen : '.$this->decimal($p->ageMoyen).' ans ; le plus jeune candidat retenu a '.$p->ageMinimum
                .' ans, le plus âgé '.$p->ageMaximum.' ans (âges au '.$this->dateReference($r).').',
                G::police(italic: true, size: 8), ['spaceBefore' => 60, 'spaceAfter' => 120]);
        }

        Tableau::parSpecialite($section, 'REPARTITION SELON LE DIPLOME D’ACCES', $p->parDiplome, 'SPECIALITE / DIPLOME', true);
        Tableau::parSpecialite($section, 'REPARTITION SELON LE DOMAINE DU DIPLOME D’ACCES', $p->parDomaine, 'SPECIALITE / DOMAINE', true);

        $this->graphiques($section, $r);
    }

    private function graphiques(Section $section, ResultatsSelection $r): void
    {
        $section->addText('REPRESENTATIONS GRAPHIQUES', self::TITRE, ['spaceBefore' => 300, 'spaceAfter' => 100, 'keepNext' => true]);

        // Graphique 1 : hommes / femmes par spécialité (sigles en abscisse).
        $sigles = array_map(fn (string $s): string => SpecialitesOfficielles::sigle($s), array_keys($r->parGenre->lignes));
        $hommes = array_map(fn (string $s): int => $r->parGenre->valeur($s, 'M'), array_keys($r->parGenre->lignes));
        $femmes = array_map(fn (string $s): int => $r->parGenre->valeur($s, 'F'), array_keys($r->parGenre->lignes));

        // Pas de couleurs imposées ici : PhpWord colore barre par barre et la
        // légende ne suivrait pas ; les couleurs de série de Word restent
        // cohérentes avec la légende.
        $genre = $section->addChart('column', $sigles, $hommes, $this->style([], legende: true), 'Hommes');
        $genre->addSeries($sigles, $femmes, 'Femmes');
        $autres = $r->parGenre->total() - $r->parGenre->totalColonne('M') - $r->parGenre->totalColonne('F');
        $this->legende($section, 'Graphique 1 : candidats retenus par spécialité et par genre'
            .($autres > 0 ? ' (hors '.$autres.' candidat'.($autres > 1 ? 's' : '').' de genre autre ou non précisé)' : ''));

        // Graphique 2 : effectifs par statut actuel, du plus fréquent au moins fréquent.
        $statuts = [];
        foreach (array_keys($r->profil->parStatut->lignes) as $statut) {
            if ($r->profil->parStatut->totalLigne($statut) > 0) {
                $statuts[$statut] = $r->profil->parStatut->totalLigne($statut);
            }
        }
        // Barres horizontales : Word dessine la première catégorie en bas.
        asort($statuts);

        if ($statuts !== []) {
            $section->addChart('bar', array_keys($statuts), array_values($statuts),
                $this->style([self::PRUNE], legende: false, hauteur: 2.2 + 0.75 * count($statuts)), 'Candidats retenus');
            $this->legende($section, 'Graphique 2 : candidats retenus selon le statut actuel');
        }
    }

    /**
     * @param  list<string>  $couleurs
     * @return array<string, mixed>
     */
    private function style(array $couleurs, bool $legende, float $hauteur = 7.5): array
    {
        return [
            'width' => (int) Converter::cmToEmu(16.5),
            'height' => (int) Converter::cmToEmu($hauteur),
            'colors' => $couleurs,
            'showLegend' => $legende,
            'legendPosition' => 'r',
            'showAxisLabels' => true,
            'showGridY' => true,
            'dataLabelOptions' => ['showVal' => true, 'showCatName' => false],
        ];
    }

    private function legende(Section $section, string $texte): void
    {
        $section->addText($texte, G::police(italic: true, size: 9), G::centre(['spaceBefore' => 60, 'spaceAfter' => 240]));
    }

    private function decimal(float $valeur): string
    {
        return number_format($valeur, 1, ',', ' ');
    }

    private function dateReference(ResultatsSelection $r): string
    {
        return ($r->seance->dateDeliberation ?? now()->toImmutable())->format('d/m/Y');
    }
}
