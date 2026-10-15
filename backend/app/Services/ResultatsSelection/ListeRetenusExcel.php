<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Models\Candidature;
use App\Models\Pays;
use App\Models\RegionCameroun;
use App\Support\TexteImprimable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Liste Excel des candidats retenus, au format de la « liste provisoire » de
 * la scolarité : en-tête bilingue et logo, titre fusionné, tableau Excel
 * filtrable (style TableStyleLight15), paysage, pied de page.
 *
 * Ajouts demandés (15 oct. 2026) : n° de dossier, établissement et année de
 * délivrance du diplôme, statut actuel, fonction, colonne « Décision » vide.
 * Améliorations : vraies dates, en-tête du tableau répété à l'impression,
 * légende des sigles, « Page X / Y ».
 */
final class ListeRetenusExcel
{
    private const POLICE = 'Arial';

    private const LIGNE_ENTETE = 14;

    /** [titre de colonne, largeur Excel] — de A à R. */
    private const COLONNES = [
        ['N°', 5], ['N° dossier', 13], ['NOM', 22], ['PRENOM', 22], ['Date Naiss.', 11], ['Lieu Naissance', 16],
        ['Région', 12], ['Sexe', 6], ['T. Etude', 7], ['Diplôme Requis', 15], ['Spécialité Requise', 20],
        ['Établissement de délivrance', 24], ['Année de délivrance', 10], ['Statut actuel', 24], ['Fonction', 20],
        ['Spécialité', 9], ['Pays', 11], ['Décision', 14],
    ];

    private const ENTETE_FR = "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n--------------\nMINISTERE DES FINANCES\n--------------\n"
        ."SECRETARIAT GENERAL\n--------------\nPROGRAMME SUPERIEUR DE SPECIALISATION EN FINANCES PUBLIQUES\n--------------";

    private const ENTETE_EN = "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n--------------\nMINISTRY OF FINANCE\n--------------\n"
        ."GENERAL SECRETARIAT\n--------------\nADVANCED PROGRAM OF SPECIALISATION IN PUBLIC FINANCE\n--------------";

    /**
     * Numérotation continue ; ordre du PV (nationaux puis étrangers). Le n° de
     * dossier sert de clé de rapprochement avec le PV et le communiqué.
     *
     * @param  Collection<int, Candidature>  $dossiers  retenus, déjà triés
     */
    public function generer(Collection $dossiers, ResultatsSelection $r): string
    {
        $classeur = new Spreadsheet;
        $classeur->getDefaultStyle()->getFont()->setName(self::POLICE)->setSize(10);
        $titre = 'CANDIDATURES A LA '.$r->promotionNumero.'ème PROMOTION DU PROGRAMME DE MASTER PROFESSIONNEL EN FINANCES PUBLIQUES';
        $classeur->getProperties()->setCreator('PSSFP')->setTitle('Liste des candidats retenus — '.$r->promotionAbregee().' promotion');

        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Candidats retenus');
        $feuille->setShowGridlines(false);
        $derniere = $this->colonne(count(self::COLONNES));

        $this->entete($feuille, $derniere);
        $this->titre($feuille, $derniere, $titre, $r, $dossiers->count());
        $fin = $this->tableau($feuille, $derniere, $dossiers);
        $this->legende($feuille, $derniere, $fin + 2);
        $this->miseEnPage($feuille, $derniere, $fin + 3, $titre.' - ANNEE ACADEMIQUE '.$r->anneeAcademique);

        return $this->enBinaire($classeur);
    }

    private function entete(Worksheet $feuille, string $derniere): void
    {
        foreach (range(1, 9) as $ligne) {
            $feuille->getRowDimension($ligne)->setRowHeight(13);
        }

        foreach ([['A1:E9', self::ENTETE_FR], ['N1:'.$derniere.'9', self::ENTETE_EN]] as [$plage, $texte]) {
            $feuille->mergeCells($plage);
            $cellule = explode(':', $plage)[0];
            $feuille->setCellValueExplicit($cellule, $texte, DataType::TYPE_STRING);
            $feuille->getStyle($plage)->getFont()->setBold(true)->setSize(9);
            $feuille->getStyle($plage)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        }

        $logo = new Drawing;
        $logo->setName('PSSFP');
        $logo->setPath(resource_path('images/pdf/logo.png'));
        $logo->setHeight(100);
        $logo->setCoordinates('I1');
        $logo->setOffsetX(10);
        $logo->setOffsetY(8);
        $logo->setWorksheet($feuille);
    }

    private function titre(Worksheet $feuille, string $derniere, string $titre, ResultatsSelection $r, int $nombre): void
    {
        $feuille->mergeCells('A11:'.$derniere.'12');
        $feuille->setCellValueExplicit('A11', $titre."\nANNEE ACADEMIQUE ".$r->anneeAcademique, DataType::TYPE_STRING);
        $feuille->getStyle('A11')->getFont()->setBold(true)->setSize(14);
        $feuille->getStyle('A11:'.$derniere.'12')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $feuille->getRowDimension(11)->setRowHeight(22);
        $feuille->getRowDimension(12)->setRowHeight(22);

        $feuille->mergeCells('A13:'.$derniere.'13');
        $feuille->setCellValueExplicit('A13', 'Liste des candidats retenus (dossier soumis en ligne et dossier papier déposé) : '
            .$nombre.' candidat'.($nombre > 1 ? 's' : '').' — état au '.now()->format('d/m/Y à H\hi'), DataType::TYPE_STRING);
        $feuille->getStyle('A13')->getFont()->setItalic(true)->setSize(9);
        $feuille->getStyle('A13')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * @param  Collection<int, Candidature>  $dossiers
     * @return int dernière ligne du tableau
     */
    private function tableau(Worksheet $feuille, string $derniere, Collection $dossiers): int
    {
        $regions = RegionCameroun::query()->pluck('nom', 'code')->all();
        $pays = Pays::query()->whereIn('code_iso', $dossiers->pluck('nationalite')->filter()->unique()->all())
            ->pluck('nom', 'code_iso')->all();
        $diplomes = (array) config('diplome_requis', []);
        $domaines = (array) config('domaines_diplome', []);
        $statuts = (array) config('statuts_actuels', []);

        foreach (self::COLONNES as $i => [$libelle, $largeur]) {
            $col = $this->colonne($i + 1);
            $feuille->getColumnDimension($col)->setWidth($largeur);
            $feuille->setCellValueExplicit($col.self::LIGNE_ENTETE, $libelle, DataType::TYPE_STRING);
        }
        $feuille->getRowDimension(self::LIGNE_ENTETE)->setRowHeight(32);
        $feuille->getStyle('A'.self::LIGNE_ENTETE.':'.$derniere.self::LIGNE_ENTETE)->getFont()->setBold(true);
        $feuille->getStyle('A'.self::LIGNE_ENTETE.':'.$derniere.self::LIGNE_ENTETE)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        $ligne = self::LIGNE_ENTETE;
        foreach ($dossiers->values() as $i => $c) {
            $ligne = self::LIGNE_ENTETE + 1 + $i;
            $valeurs = [
                $i + 1,
                (string) $c->numero_dossier,
                TexteImprimable::majuscules($c->nom),
                TexteImprimable::majuscules($c->prenom),
                $c->date_naissance === null ? null : Date::PHPToExcel($c->date_naissance->toDateTime()),
                TexteImprimable::nettoyer($c->lieu_naissance),
                $regions[(string) $c->region] ?? '',
                match ($c->genre) {
                    'M' => 'H', 'F' => 'F', null => '', default => 'Autre'
                },
                match ($c->type_etude) {
                    'presentiel' => 'P', 'distanciel' => 'D', default => ''
                },
                $diplomes[(string) $c->diplome_requis] ?? '',
                // Saisie libre seulement pour le domaine « autres » ; sinon le
                // domaine (Droit, Économie, Gestion) tient lieu de spécialité.
                TexteImprimable::nettoyer($c->specialite_diplome_requis ?: ($domaines[(string) $c->domaine_diplome_requis] ?? '')),
                TexteImprimable::nettoyer($c->institut_diplome_requis),
                $c->annee_diplome_requis === null ? null : (int) $c->annee_diplome_requis,
                $statuts[(string) $c->statut_actuel] ?? '',
                TexteImprimable::nettoyer($c->fonction_actuelle),
                SpecialitesOfficielles::sigle(SpecialitesOfficielles::libelle($c->specialite)),
                $pays[(string) $c->nationalite] ?? '',
                '',
            ];

            foreach ($valeurs as $j => $valeur) {
                $cellule = $this->colonne($j + 1).$ligne;
                // Texte saisi par le candidat : toujours en chaîne, jamais
                // interprété comme une formule (« =… »).
                is_string($valeur)
                    ? $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_STRING)
                    : $feuille->setCellValue($cellule, $valeur);
            }
        }

        // Hauteur uniforme (deux lignes de texte) : tableau régulier à l'écran
        // comme à l'impression, quelle que soit la longueur des libellés.
        foreach (range(self::LIGNE_ENTETE + 1, max($ligne, self::LIGNE_ENTETE + 1)) as $numero) {
            $feuille->getRowDimension($numero)->setRowHeight(26);
        }

        $corps = 'A'.(self::LIGNE_ENTETE + 1).':'.$derniere.max($ligne, self::LIGNE_ENTETE + 1);
        $feuille->getStyle($corps)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $feuille->getStyle('E'.(self::LIGNE_ENTETE + 1).':E'.$ligne)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        foreach (['A', 'B', 'E', 'H', 'I', 'M', 'P'] as $centre) {
            $feuille->getStyle($centre.(self::LIGNE_ENTETE + 1).':'.$centre.$ligne)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Un tableau Excel exige au moins une ligne de données.
        $fin = max($ligne, self::LIGNE_ENTETE + 1);
        $table = new Table('A'.self::LIGNE_ENTETE.':'.$derniere.$fin, 'CandidatsRetenus');
        $table->setStyle((new TableStyle)->setTheme(TableStyle::TABLE_STYLE_LIGHT15)->setShowRowStripes(true));
        $feuille->addTable($table);

        return $fin;
    }

    private function legende(Worksheet $feuille, string $derniere, int $ligne): void
    {
        $sigles = [];
        foreach (SpecialitesOfficielles::ordonnees() as $libelle) {
            $sigles[] = SpecialitesOfficielles::sigle($libelle).' : '.$libelle;
        }

        foreach ([
            'Spécialités — '.implode(' ; ', $sigles).'.',
            'Sexe : H = homme, F = femme. T. Etude : P = présentiel, D = distanciel. Colonne « Décision » à renseigner par le jury.',
        ] as $k => $texte) {
            $feuille->mergeCells('A'.($ligne + $k).':'.$derniere.($ligne + $k));
            $feuille->setCellValueExplicit('A'.($ligne + $k), $texte, DataType::TYPE_STRING);
            $feuille->getStyle('A'.($ligne + $k))->getFont()->setItalic(true)->setSize(8);
        }
    }

    private function miseEnPage(Worksheet $feuille, string $derniere, int $fin, string $pied): void
    {
        $page = $feuille->getPageSetup();
        $page->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setHorizontalCentered(true)
            ->setPrintArea('A1:'.$derniere.$fin)
            ->setRowsToRepeatAtTopByStartAndEnd(self::LIGNE_ENTETE, self::LIGNE_ENTETE);

        $feuille->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5)->setHeader(0.25)->setFooter(0.25);
        $feuille->getHeaderFooter()->setOddFooter('&L&8'.str_replace('&', '&&', $pied).'&R&8Page &P / &N');
        $feuille->setSelectedCell('A'.(self::LIGNE_ENTETE + 1));
    }

    private function colonne(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    private function enBinaire(Spreadsheet $classeur): string
    {
        $fichier = tempnam(sys_get_temp_dir(), 'pssfp-xlsx-');
        if ($fichier === false) {
            throw new RuntimeException('Impossible de créer le fichier temporaire du classeur Excel.');
        }

        try {
            (new Xlsx($classeur))->save($fichier);

            return (string) file_get_contents($fichier);
        } finally {
            @unlink($fichier);
            $classeur->disconnectWorksheets();
        }
    }
}
