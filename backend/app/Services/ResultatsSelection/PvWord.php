<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Services\ResultatsSelection\WordGabarit as G;
use App\Support\NombreEnLettres;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * PV des délibérations du jury de sélection, au format Word. Même contenu que
 * la vue `pdf.resultats.pv`.
 */
final class PvWord
{
    private const TITRE = ['bold' => true, 'underline' => 'single'];

    private const TITRE_PARAGRAPHE = ['spaceBefore' => 200, 'spaceAfter' => 100, 'keepNext' => true];

    public function generer(ResultatsSelection $r): string
    {
        $word = G::document();
        $section = G::section($word);

        // Première page : en-tête officiel ; pages suivantes : rappel du PV.
        $section->addHeader(Header::FIRST)->addImage(G::image('entete.png'), ['width' => Converter::cmToPoint(17.4), 'alignment' => Jc::CENTER]);
        $section->addHeader()->addText(
            'PV/sélection/'.$r->seance->dateCourte().' / Programme Supérieur de Spécialisation en Finances Publiques (PSSFP)',
            ['name' => G::TABLEAU, 'size' => 7.5, 'color' => '555555'],
        );

        $this->preambule($section, $r);
        $this->methodologie($section);
        $this->examen($section, $r);
        $this->listes($section, $r);
        $this->signatures($section, $r);

        return G::enBinaire($word);
    }

    private function preambule(Section $section, ResultatsSelection $r): void
    {
        $ref = G::colonnes($section);
        $ref->addRow();
        $ref->addCell(intdiv(G::LARGEUR, 2) + 1500)->addText($r->seance->referencePv(), ['bold' => true, 'size' => 9.5]);
        $ref->addCell(intdiv(G::LARGEUR, 2) - 1500)->addText('Yaoundé, le '.SeanceJury::BLANC, ['bold' => true, 'size' => 9.5], ['alignment' => Jc::END]);

        $cadre = $section->addTable(['borderSize' => 12, 'borderColor' => '1F1F1F', 'cellMargin' => 140] + G::LARGEUR_FIXE);
        $cadre->addRow();
        $cellule = $cadre->addCell(G::LARGEUR);
        $cellule->addText('PROCES-VERBAL DU JURY DE SELECTION DES CANDIDATS A LA '.$r->promotionOrdinal()
            .' PROMOTION DU PROGRAMME DE MASTER PROFESSIONNEL EN FINANCES PUBLIQUES', ['bold' => true, 'size' => 12], G::CENTRE);
        $cellule->addText('ANNEE ACADEMIQUE '.$r->anneeAcademique, ['bold' => true], G::centre(['spaceBefore' => 80]));
        $section->addTextBreak(1, ['size' => 6]);

        $section->addText($r->seance->dateEnLettres().', s’est réuni, dans les locaux du Programme Supérieur de Spécialisation en Finances Publiques (PSSFP), le jury de sélection des candidats à la '
            .NombreEnLettres::ordinal($r->promotionNumero).' promotion du Master Professionnel en Finances Publiques, au titre de l’année académique '.$r->anneeAcademique.'.', [], G::JUSTIFIE);
        $section->addText('Ont pris part aux travaux du jury, les membres suivants désignés par la note de service '.$r->seance->noteService()
            .' portant désignation des membres du jury de sélection des candidats de la '.$r->promotionAbregee().' promotion pour l’admission en 1ère année de Master.', [], G::JUSTIFIE);

        $table = G::grille($section);
        $largeurs = [600, 2900, 1700, G::LARGEUR - 5200];
        G::enTete($table, [['N°', $largeurs[0]], ['Noms et prénoms', $largeurs[1]], ['Qualité', $largeurs[2]], ['Fonction', $largeurs[3]]]);
        foreach ($r->jury as $i => $m) {
            G::ligne($table, [[(string) ($i + 1), $largeurs[0], true], [$m['nom'], $largeurs[1]], [$m['qualite'], $largeurs[2]], [$m['fonction'], $largeurs[3]]]);
        }
    }

    private function methodologie(Section $section): void
    {
        $section->addText('METHODOLOGIE', self::TITRE, self::TITRE_PARAGRAPHE);
        $section->addText('Les membres du jury ont procédé au rappel des critères de sélection tenant aux conditions d’éligibilité et critères académiques d’une part, puis aux critères socioprofessionnels liés à la valorisation des acquis de l’expérience et à la pertinence du projet professionnel du candidat pour la formation sollicitée. Ainsi, outre les critères académiques, les principales exigences suivantes ont été retenues :', [], G::JUSTIFIE);
        foreach ((array) config('resultats_selection.criteres') as $critere) {
            $section->addListItem($critere, 0, [], null, ['spaceAfter' => 0]);
        }
    }

    private function examen(Section $section, ResultatsSelection $r): void
    {
        $section->addText('PROCESSUS D’EXAMEN DES DOSSIERS', self::TITRE, self::TITRE_PARAGRAPHE);
        $section->addText('Le secrétariat technique conduit par '.config('resultats_selection.secretariat_technique')
            .', a procédé au préalable à l’examen des inscriptions en ligne des '.$r->totalEnLigne.' candidats afin de s’assurer du dépôt physique effectif des dossiers. Par la suite, le jury a examiné chacun des dossiers qui lui ont été présentés, aussi bien dans la forme que dans le fond, sur la base des critères prédéfinis. Un total de '
            .$r->totalRetenus().' dossiers physiques a ainsi été examiné et retenu. Ces dossiers se répartissent suivant les critères de nationalité, de genre et de mode de formation de la manière suivante :', [], G::JUSTIFIE);

        $this->repartition($section, 'REPARTITION GENERALE DES DOSSIERS DEPOSES PAR PAYS', $r->parPays, 'SPECIALITE \\ PAYS', false);
        $this->repartition($section, 'REPARTITION DES DOSSIERS DEPOSES SELON LE GENRE', $r->parGenre, 'SPECIALITE / GENRE', true);
        $this->repartition($section, 'REPARTITION DES DOSSIERS DEPOSES PAR MODE DE FORMATION', $r->parMode, 'SPECIALITE / MODE DE FORMATION', true);
    }

    private function repartition(Section $section, string $titre, Repartition $rep, string $entete, bool $pourcentages): void
    {
        $section->addText($titre, ['bold' => true, 'size' => 10], G::centre(['spaceBefore' => 200, 'spaceAfter' => 60, 'keepNext' => true]));

        $colonnes = array_keys($rep->colonnes);
        $nbValeurs = count($colonnes) + ($pourcentages ? 0 : 1);
        $largeurValeur = min(1400, intdiv(G::LARGEUR - 600 - 3600, max(1, $nbValeurs)));
        $largeurSpecialite = G::LARGEUR - 600 - $largeurValeur * $nbValeurs;

        $table = G::grille($section);
        $enTete = [['N°', 600], [$entete, $largeurSpecialite]];
        foreach ($rep->colonnes as $libelle) {
            $enTete[] = [$libelle, $largeurValeur];
        }
        if (! $pourcentages) {
            $enTete[] = ['TOTAL', $largeurValeur];
        }
        G::enTete($table, $enTete);

        foreach (array_keys($rep->lignes) as $i => $specialite) {
            $cellules = [[(string) ($i + 1), 600, true], [$specialite, $largeurSpecialite]];
            foreach ($colonnes as $col) {
                $cellules[] = [(string) $rep->valeur($specialite, $col), $largeurValeur, true];
            }
            if (! $pourcentages) {
                $cellules[] = [(string) $rep->totalLigne($specialite), $largeurValeur, true];
            }
            G::ligne($table, $cellules);
        }

        $totaux = [['', 600], ['TOTAL', $largeurSpecialite]];
        foreach ($colonnes as $col) {
            $totaux[] = [(string) $rep->totalColonne($col), $largeurValeur, true];
        }
        if (! $pourcentages) {
            $totaux[] = [(string) $rep->total(), $largeurValeur, true];
        }
        G::ligne($table, $totaux, total: true);

        if ($pourcentages) {
            $parts = [['', 600], ['EN % DU TOTAL', $largeurSpecialite]];
            foreach ($colonnes as $col) {
                $parts[] = [$rep->pourcentage($col), $largeurValeur, true];
            }
            G::ligne($table, $parts, total: true);
        }

        $this->ligneTotalGeneral($table, $pourcentages ? 'TOTAL GENERAL' : 'TOTAL DEPOT PHYSIQUE DES DOSSIERS',
            $largeurSpecialite, $largeurValeur * $nbValeurs, $nbValeurs, $rep->total());
    }

    private function ligneTotalGeneral(Table $table, string $libelle, int $largeurLibelle, int $largeurValeurs, int $fusion, int $total): void
    {
        $fond = ['bgColor' => 'F2F2F2', 'valign' => 'center'];
        $table->addRow(null, ['cantSplit' => true]);
        $table->addCell(600, $fond);
        $table->addCell($largeurLibelle, $fond)->addText($libelle, G::police(bold: true), ['spaceAfter' => 0]);
        $table->addCell($largeurValeurs, $fond + ['gridSpan' => $fusion])->addText((string) $total, G::police(bold: true), G::CENTRE);
    }

    private function listes(Section $section, ResultatsSelection $r): void
    {
        $section->addText('LISTE DES CANDIDATS RETENUS POUR LA FORMATION', self::TITRE, self::TITRE_PARAGRAPHE);
        $section->addText('Après avoir délibéré, le jury a retenu, sous réserve de la présentation des originaux de leurs diplômes, les candidats dont les noms suivent classés par ordre alphabétique :', [], G::JUSTIFIE);

        foreach ([['Candidats nationaux :', $r->nationaux, false], ['Candidats étrangers :', $r->etrangers, true]] as [$titre, $candidats, $etrangers]) {
            $section->addText($titre, self::TITRE, ['spaceBefore' => 120, 'spaceAfter' => 60]);
            if ($candidats === []) {
                $section->addText('Néant', ['italic' => true], ['indentation' => ['left' => 400]]);

                continue;
            }

            $mode = 1500;
            $nationalite = $etrangers ? 1700 : 0;
            $nom = intdiv(G::LARGEUR - 600 - $mode - $nationalite, 2);
            $prenoms = G::LARGEUR - 600 - $mode - $nationalite - $nom;

            $table = G::grille($section);
            G::enTete($table, array_values(array_filter([
                ['N°', 600], ['NOMS', $nom], ['PRENOMS', $prenoms],
                $etrangers ? ['NATIONALITE', $nationalite] : null,
                ['MODE FORMATION', $mode],
            ])));
            foreach ($candidats as $i => $c) {
                G::ligne($table, array_values(array_filter([
                    [(string) ($i + 1), 600, true], [$c->nom, $nom], [$c->prenoms, $prenoms],
                    $etrangers ? [$c->nationalite, $nationalite, true] : null,
                    [$c->mode, $mode, true],
                ])));
            }
        }

        $legende = ['name' => G::TABLEAU, 'size' => 9, 'italic' => true];
        $section->addText('(P) = Présentiel', $legende, ['indentation' => ['left' => 3400], 'spaceBefore' => 60, 'spaceAfter' => 0]);
        $section->addText('(D) = Distanciel', $legende, ['indentation' => ['left' => 3400], 'spaceAfter' => 240]);
    }

    private function signatures(Section $section, ResultatsSelection $r): void
    {
        $section->addText('Yaoundé, le '.SeanceJury::BLANC.'……………', [], ['alignment' => Jc::END, 'spaceAfter' => 240, 'keepNext' => true]);

        $moitie = intdiv(G::LARGEUR, 2);
        $table = G::colonnes($section);
        foreach ([['LE RAPPORTEUR', 'rapporteur', 'LE PRESIDENT DU JURY', 'president'],
            ['1er VICE PRESIDENT DU JURY', 'vice_president_1', '2e VICE PRESIDENT DU JURY', 'vice_president_2']] as [$g, $rg, $d, $rd]) {
            $table->addRow(null, ['cantSplit' => true]);
            foreach ([[$g, $rg], [$d, $rd]] as [$titre, $role]) {
                $cellule = $table->addCell($moitie);
                // Lignes vides plutôt qu'un espacement de paragraphe : certains
                // traitements de texte ignorent l'espacement dans les cellules.
                $cellule->addText($titre, ['bold' => true, 'size' => 10.5], G::CENTRE);
                $cellule->addTextBreak(4, ['size' => 10.5]);
                $cellule->addText($r->membre($role)['nom'] ?? SeanceJury::BLANC, ['size' => 10.5], G::CENTRE);
                $cellule->addTextBreak(1, ['size' => 10.5]);
            }
        }

        $section->addText('LES MEMBRES', ['bold' => true, 'size' => 10.5], G::centre(['spaceBefore' => 240, 'spaceAfter' => 0, 'keepNext' => true]));
        $membres = G::colonnes($section);
        foreach (array_chunk($r->membresSimples(), 2) as $paire) {
            $membres->addRow(null, ['cantSplit' => true]);
            foreach ([0, 1] as $j) {
                $cellule = $membres->addCell($moitie);
                $cellule->addTextBreak(3, ['size' => 10.5]);
                $cellule->addText($paire[$j]['nom'] ?? '', ['size' => 10.5], G::CENTRE);
            }
        }
    }
}
