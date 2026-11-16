<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\InscriptionAnnuelle;
use App\Models\RubriqueFrais;
use Illuminate\Support\Facades\Log;

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
     * Ventilation du reçu (R2) : par rubriques de frais de l'année et du niveau de l'inscription désignée quand elles sont
     * utilisables (actives, somme égale au barème), sinon par palier.
     *
     * @return list<array{libelle: string, code: ?string, du: ?int, ce_versement: int, total_verse: ?int, reste: ?int}>
     */
    public static function pour(InscriptionAnnuelle $designee, int $avant, int $part, int $autresAnnees, int $avance): array
    {
        $brut = (int) $designee->montant_inscription + (int) $designee->montant_tranche1 + (int) $designee->montant_tranche2;
        $annee = (string) $designee->annee_academique;
        $niveau = (string) $designee->niveau;
        $rubriques = RubriquesFrais::utilisables($annee, $niveau, $brut);
        if ($rubriques === null && RubriqueFrais::query()->duCouple($annee, $niveau)->exists()) {
            // Repli jamais silencieux : le couple a des rubriques, mais elles ne servent pas (somme ≠ barème, ou montants de
            // l'inscription différents du barème). L'écran Rubriques le signale aussi.
            $etat = RubriquesFrais::etat($annee, $niveau);
            Log::warning('Reçu ventilé par palier : les rubriques du couple ne servent pas.', [
                'annee' => $annee, 'niveau' => $niveau, 'raison' => $etat['raison'] ?? 'montants de l\'inscription différents du barème',
            ]);
            activity('finances')->event('ventilation_repli')
                ->withProperties(['annee' => $annee, 'niveau' => $niveau, 'raison' => $etat['raison'] ?? 'montants de l\'inscription différents du barème'])
                ->log('Reçu ventilé par palier faute de rubriques utilisables');
        }

        return $rubriques === null
            ? self::parPalier($designee, $avant, $part, $autresAnnees, $avance)
            : self::parRubriques($rubriques->map(fn (RubriqueFrais $r): array => [
                'libelle' => $r->libelle, 'montant' => $r->montant,
                // Pension : compte de la promotion du dossier de l'auditeur (plan comptable de la RAF) ; « à préciser » sans compte.
                'code' => $r->compte_selon_promotion ? $designee->auditeur?->candidature?->campagne?->compte_scolarite : $r->code_imputation,
            ])->values()->all(), (int) $designee->remise_montant, $avant, $part, $autresAnnees, $avance);
    }

    /**
     * Ventilation par rubriques, comme le reçu ACMA : le cumul imputé sur l'inscription remplit chaque rubrique avant la
     * suivante (frais fixes, puis pension). La remise réduit la dernière rubrique (la pension), puis les précédentes si elle
     * la dépasse ; elle est écrite dans le libellé. Ce versement = part du cumul après lui moins part du cumul avant lui.
     *
     * @param  list<array{libelle: string, montant: int, code: ?string}>  $rubriques  dans l'ordre de répartition
     * @return list<array{libelle: string, code: ?string, du: ?int, ce_versement: int, total_verse: ?int, reste: ?int}>
     */
    public static function parRubriques(array $rubriques, int $remise, int $avant, int $part, int $autresAnnees, int $avance): array
    {
        $restant = max(0, $remise);
        $deductions = array_fill(0, count($rubriques), 0);
        for ($k = count($rubriques) - 1; $k >= 0 && $restant > 0; $k--) {
            $deductions[$k] = min($restant, $rubriques[$k]['montant']);
            $restant -= $deductions[$k];
        }
        $apres = $avant + $part;
        $lignes = [];
        $borne = 0;
        foreach ($rubriques as $k => $r) {
            $du = $r['montant'] - $deductions[$k];
            $cumulAvant = max(0, min($du, $avant - $borne));
            $cumulApres = max(0, min($du, $apres - $borne));
            $libelle = $deductions[$k] > 0 ? $r['libelle'].' (remise de '.RecusVersements::fcfa($deductions[$k]).' déduite)' : $r['libelle'];
            $lignes[] = self::ligne($libelle, $r['code'], $du, $cumulApres - $cumulAvant, $cumulApres);
            $borne += $du;
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
