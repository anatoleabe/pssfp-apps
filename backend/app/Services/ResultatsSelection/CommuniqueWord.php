<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Services\ResultatsSelection\WordGabarit as G;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Communiqué conjoint MINFI / UY2 des résultats, au format Word. Même contenu
 * que la vue `pdf.resultats.communique`.
 */
final class CommuniqueWord
{
    public function generer(ResultatsSelection $r): string
    {
        $word = G::document();
        $section = G::section($word);
        $annee = str_replace('-', ' – ', $r->anneeAcademique);

        $this->entete($section);

        $section->addText('JOINT PRESS RELEASE / COMMUNIQUE CONJOINT', ['size' => 16, 'bold' => true],
            ['alignment' => Jc::CENTER, 'spaceBefore' => 160, 'spaceAfter' => 40]);
        $section->addText($r->seance->referenceCommunique().' of '.SeanceJury::BLANC, ['bold' => true],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 160]);

        $section->addText("Announcing the results of the call for application for the entrance into the first year of the Master's degree cycle in Public Finance, for the {$annee} Academic year.",
            [], G::centre(['spaceAfter' => 100, 'lineHeight' => 1.15]));
        $section->addText("Portant publication des résultats de l’appel à candidatures pour l’entrée en première année du cycle de Master en Finances Publiques au titre de l’année académique {$annee}.",
            ['italic' => true], G::centre(['spaceAfter' => 200, 'lineHeight' => 1.15]));

        $section->addText('THE MINISTER OF FINANCE AND THE RECTOR OF THE UNIVERSITY OF YAOUNDE II – SOA', ['bold' => true, 'size' => 11], G::CENTRE);
        $section->addText('LE MINISTRE DES FINANCES ET LE RECTEUR DE L’UNIVERSITE DE YAOUNDE II – SOA', ['bold' => true, 'italic' => true, 'size' => 11],
            G::centre(['spaceBefore' => 80, 'spaceAfter' => 120]));

        $annonce = $section->addTextRun(G::centre(['spaceAfter' => 120]));
        $annonce->addText('ANNOUNCE / ', ['bold' => true]);
        $annonce->addText('COMMUNIQUENT', ['bold' => true, 'italic' => true]);
        $annonce->addText(' :', ['bold' => true]);

        $section->addText("The following candidates, listed in alphabetical order, are declared admitted to the first year of the Master's degree in Public Finance for the {$r->anneeAcademiqueSlash()} academic year, subject to presentation of their original diplomas.",
            [], G::JUSTIFIE);
        $section->addText("Les candidats dont les noms suivent, classés par ordre alphabétique, sont déclarés admis, sous réserve de la présentation des originaux de leurs diplômes, en première année de Master en Finances Publiques au titre de l’année académique {$r->anneeAcademiqueSlash()}.",
            ['italic' => true], G::JUSTIFIE);

        $ilSagit = $section->addTextRun(G::centre(['spaceAfter' => 160]));
        $ilSagit->addText('They are / ');
        $ilSagit->addText('Il s’agit de', ['italic' => true]);
        $ilSagit->addText(' :');

        $this->titreListe($section, 'National candidates / ', 'Candidats nationaux');
        $this->liste($section, $r->nationaux, avecNationalite: false);

        $this->titreListe($section, 'Foreign candidates / ', 'Candidats étrangers');
        $this->liste($section, $r->etrangers, avecNationalite: true);

        $legende = ['name' => G::TABLEAU, 'size' => 9, 'italic' => true];
        $section->addText('(P) = Présentiel / In-person', $legende, ['indentation' => ['left' => 3400], 'spaceAfter' => 0]);
        $section->addText('(D) = Distanciel / Distance learning', $legende, ['indentation' => ['left' => 3400], 'spaceAfter' => 240]);

        $this->signatures($section);

        return G::enBinaire($word);
    }

    private function entete(Section $section): void
    {
        $table = G::colonnes($section);
        $table->addRow();
        $style = ['size' => 8];

        $gauche = $table->addCell(3300);
        foreach ((array) config('resultats_selection.entete_universite') as $ligne) {
            $gauche->addText($ligne, $style, G::CENTRE);
        }

        $logos = $table->addCell(G::LARGEUR - 6600, ['valign' => 'center'])->addTextRun(G::CENTRE);
        $logos->addImage(G::image('uy2.png'), ['height' => 54]);
        $logos->addText(' ');
        $logos->addImage(G::image('logo.png'), ['height' => 54]);

        $droite = $table->addCell(3300);
        foreach ((array) config('resultats_selection.entete_minfi') as $ligne) {
            $droite->addText($ligne, $style, G::CENTRE);
        }
    }

    private function titreListe(Section $section, string $anglais, string $francais): void
    {
        $titre = $section->addTextRun(['spaceBefore' => 160, 'spaceAfter' => 60]);
        $titre->addText($anglais, ['bold' => true]);
        $titre->addText($francais, ['bold' => true, 'italic' => true]);
        $titre->addText(' :', ['bold' => true]);
    }

    /** @param  list<CandidatRetenu>  $candidats */
    private function liste(Section $section, array $candidats, bool $avecNationalite): void
    {
        if ($candidats === []) {
            $section->addText('None / Néant', ['italic' => true], ['indentation' => ['left' => 400]]);

            return;
        }

        $mode = 2000;
        $nationalite = $avecNationalite ? 2000 : 0;
        $nom = G::LARGEUR - 600 - $mode - $nationalite;

        $table = G::grille($section);
        G::enTete($table, array_values(array_filter([
            ['N°', 600],
            ['FULL NAME / NOMS ET PRENOMS', $nom],
            $avecNationalite ? ['NATIONALITY / NATIONALITE', $nationalite] : null,
            ['TRAINING METHOD / MODE FORMATION', $mode],
        ])));

        foreach ($candidats as $i => $c) {
            G::ligne($table, array_values(array_filter([
                [(string) ($i + 1), 600, true],
                [$c->nomComplet(), $nom],
                $avecNationalite ? [$c->nationalite, $nationalite, true] : null,
                [$c->mode, $mode, true],
            ])));
        }
    }

    private function signatures(Section $section): void
    {
        $section->addText('Yaoundé, le '.SeanceJury::BLANC.'……………', [], ['alignment' => Jc::END, 'spaceAfter' => 200, 'keepNext' => true]);

        $table = G::colonnes($section);
        $table->addRow(null, ['cantSplit' => true]);
        foreach ([
            ['The Rector of the University of Yaoundé II', 'Le Recteur de l’Université de Yaoundé II'],
            ['The Minister of Finance', 'Le Ministre des Finances'],
        ] as [$anglais, $francais]) {
            $cellule = $table->addCell(intdiv(G::LARGEUR, 2));
            $cellule->addText($anglais, ['bold' => true, 'underline' => 'single', 'size' => 10.5], G::CENTRE);
            $cellule->addText($francais, ['italic' => true, 'underline' => 'single', 'size' => 10.5], G::CENTRE);
            $cellule->addTextBreak(5, ['size' => 10.5]);
        }
    }
}
