<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\InscriptionAnnuelle;

/**
 * Ventilation d'un versement sur son reçu (reçu A4, R1), figée à l'émission dans `recus_versements.ventilation`. Calcul pur,
 * sans base : le cumul imputé sur l'inscription désignée avant et après le versement se répartit sur les paliers (droits
 * d'inscription, 1re tranche, 2e tranche), chacun rempli avant le suivant, comme la situation de la fiche.
 *
 * Chaque ligne : libellé, code d'imputation (nul tant que la comptabilité ne l'a pas donné), dû, part de ce versement,
 * total versé après lui, reste. Une part imputée sur d'autres années et une avance ont leur ligne, sans dû ni reste.
 */
final class VentilationRecu
{
    public const LIBELLES_PALIERS = [
        Palier::INSCRIPTION => 'Droits d\'inscription',
        Palier::TRANCHE1 => '1re tranche',
        Palier::TRANCHE2 => '2e tranche',
    ];

    public const LIBELLE_AUTRES_ANNEES = 'Autres années';

    public const LIBELLE_AVANCE = 'Avance sur la scolarité à venir';

    /**
     * @param  int  $avant  imputé sur l'inscription désignée par les versements antérieurs
     * @param  int  $part  part de ce versement imputée sur l'inscription désignée
     * @param  int  $autresAnnees  part de ce versement imputée sur d'autres inscriptions
     * @param  int  $avance  part de ce versement imputée nulle part
     * @return list<array{libelle: string, code: ?string, du: ?int, ce_versement: int, total_verse: ?int, reste: ?int}>
     */
    public static function parPalier(InscriptionAnnuelle $designee, int $avant, int $part, int $autresAnnees, int $avance): array
    {
        $situation = SituationFinanciere::calculer($designee->montant_inscription, $designee->montant_tranche1, $designee->montant_tranche2,
            (int) $designee->remise_montant, 0, InscriptionAnnuelle::aujourdhui());
        $apres = $avant + $part;
        $lignes = [];
        $borne = 0;
        foreach ($situation->paliers as $p) {
            $cumulAvant = max(0, min($p->du, $avant - $borne));
            $cumulApres = max(0, min($p->du, $apres - $borne));
            $lignes[] = self::ligne(self::LIBELLES_PALIERS[$p->cle], null, $p->du, $cumulApres - $cumulAvant, $cumulApres);
            $borne += $p->du;
        }

        return [...$lignes, ...self::horsAnnee($autresAnnees, $avance)];
    }

    /**
     * Lignes d'une part sur d'autres années et d'une avance, quand elles existent.
     *
     * @return list<array{libelle: string, code: ?string, du: ?int, ce_versement: int, total_verse: ?int, reste: ?int}>
     */
    public static function horsAnnee(int $autresAnnees, int $avance): array
    {
        $lignes = [];
        if ($autresAnnees > 0) {
            $lignes[] = ['libelle' => self::LIBELLE_AUTRES_ANNEES, 'code' => null, 'du' => null, 'ce_versement' => $autresAnnees, 'total_verse' => null, 'reste' => null];
        }
        if ($avance > 0) {
            $lignes[] = ['libelle' => self::LIBELLE_AVANCE, 'code' => null, 'du' => null, 'ce_versement' => $avance, 'total_verse' => null, 'reste' => null];
        }

        return $lignes;
    }

    /** @return array{libelle: string, code: ?string, du: int, ce_versement: int, total_verse: int, reste: int} */
    public static function ligne(string $libelle, ?string $code, int $du, int $ceVersement, int $totalVerse): array
    {
        return ['libelle' => $libelle, 'code' => $code, 'du' => $du, 'ce_versement' => $ceVersement, 'total_verse' => $totalVerse, 'reste' => $du - $totalVerse];
    }
}
