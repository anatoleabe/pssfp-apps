<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Situation financière des auditeurs, une ligne par auditeur, sans téléphone.
 * Les textes sont écrits comme du texte, jamais interprétés comme une formule.
 */
final class SituationFinanciereExcel
{
    public const ENTETES = ['Matricule', 'Nom', 'Prénom', 'Promotion', 'Niveau', 'Année', 'Dû', 'Remise', 'Versé', 'Reste', 'Situation'];

    /** @param  Collection<int, Auditeur>  $auditeurs */
    public function generer(Collection $auditeurs): string
    {
        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Situation');
        $feuille->fromArray(self::ENTETES, null, 'A1');
        $feuille->getStyle('A1:K1')->getFont()->setBold(true);

        $ligne = 2;
        foreach ($auditeurs as $auditeur) {
            $c = $auditeur->candidature;
            $i = $auditeur->inscriptionCourante;
            $s = $i?->situation();
            $textes = ['A' => $c?->matricule, 'B' => $c?->nom, 'C' => $c?->prenom, 'E' => $i?->niveau, 'F' => $i?->annee_academique, 'K' => LibelleSituation::libelle($i)];
            foreach ($textes as $colonne => $valeur) {
                $feuille->setCellValueExplicit($colonne.$ligne, (string) $valeur, DataType::TYPE_STRING);
            }
            $feuille->setCellValue('D'.$ligne, $c?->campagne?->promotion_numero);
            if ($s !== null) {
                $feuille->setCellValue('G'.$ligne, $s->duTotal);
                $feuille->setCellValue('H'.$ligne, $s->remise);
                $feuille->setCellValue('I'.$ligne, $s->verse);
                $feuille->setCellValue('J'.$ligne, $s->reste);
            }
            $ligne++;
        }
        foreach (range('A', 'K') as $colonne) {
            $feuille->getColumnDimension($colonne)->setAutoSize(true);
        }
        $feuille->getStyle('G2:J'.max(2, $ligne - 1))->getNumberFormat()->setFormatCode('#,##0');

        ob_start();
        (new Xlsx($classeur))->save('php://output');

        return (string) ob_get_clean();
    }
}
