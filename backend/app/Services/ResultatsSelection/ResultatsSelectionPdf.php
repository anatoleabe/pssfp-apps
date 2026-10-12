<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Canvas;
use Dompdf\FontMetrics;

/**
 * Rendu PDF (dompdf) du communiqué des résultats et du PV du jury, prêts à
 * imprimer et à signer à la main : aucune signature ni cachet n'y figure.
 */
final class ResultatsSelectionPdf
{
    public function communique(ResultatsSelection $resultats): string
    {
        return $this->rendre('pdf.resultats.communique', $resultats, enteteCourant: null);
    }

    public function pv(ResultatsSelection $resultats): string
    {
        // En-tête courant des pages suivantes, repris du modèle Word du PV.
        $enteteCourant = 'PV/sélection/'.$resultats->seance->dateCourte()
            .' / Programme Supérieur de Spécialisation en Finances Publiques (PSSFP)';

        return $this->rendre('pdf.resultats.pv', $resultats, $enteteCourant);
    }

    private function rendre(string $vue, ResultatsSelection $resultats, ?string $enteteCourant): string
    {
        $html = view($vue, [
            'r' => $resultats,
            'entete' => self::image('entete.png'),
            'logoPssfp' => self::image('logo.png'),
            'logoUy2' => self::image('uy2.png'),
            'enteteUniversite' => (array) config('resultats_selection.entete_universite'),
            'enteteMinfi' => (array) config('resultats_selection.entete_minfi'),
            'piedDePage' => (array) config('resultats_selection.pied_de_page'),
            'criteres' => (array) config('resultats_selection.criteres'),
            'secretariat' => (string) config('resultats_selection.secretariat_technique'),
        ])->render();

        // Sous-ensemble des polices : seuls les glyphes utilisés sont embarqués.
        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $dompdf->getCanvas()->page_script(
            function (int $page, int $pages, Canvas $canvas, FontMetrics $metrics) use ($enteteCourant): void {
                $police = $metrics->getFont('Source Sans 3', 'normal') ?: $metrics->getFont('DejaVu Sans', 'normal');
                $gris = [0.35, 0.35, 0.35];

                $numero = "{$page} / {$pages}";
                $canvas->text(
                    $canvas->get_width() - 51 - $metrics->getTextWidth($numero, $police, 8),
                    $canvas->get_height() - 30,
                    $numero, $police, 8, $gris,
                );

                if ($enteteCourant !== null && $page > 1) {
                    $canvas->text(51, 20, $enteteCourant, $police, 7.5, $gris);
                }
            },
        );

        return $pdf->output();
    }

    /** Images embarquées en base64 : dompdf n'a pas à lire le disque ni le réseau. */
    public static function image(string $fichier): string
    {
        $chemin = resource_path('images/pdf/'.$fichier);

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($chemin));
    }
}
