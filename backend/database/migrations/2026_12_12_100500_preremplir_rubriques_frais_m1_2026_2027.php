<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rubriques du M1 2026-2027 (reçu A4, R2), d'après le reçu actuel et le plan comptable des produits de la RAF : droits
 * d'inscription de 185 000 FCFA ventilés en inscription, bibliothèque, tenue de sport et périscolaires, puis la pension,
 * dont le compte est celui de la promotion. Total 1 185 000 FCFA, celui du barème M1. Le M2 reste vide : sa ventilation
 * retombe sur les paliers jusqu'à ce que l'administrateur saisisse ses rubriques.
 *
 * N'écrit rien si le couple a déjà des rubriques ; `down()` ne retire que des lignes restées telles quelles.
 */
return new class extends Migration
{
    private const ANNEE = '2026-2027';

    private const NIVEAU = 'M1';

    /** [ordre, libellé, code d'imputation, montant, compte selon la promotion] */
    private const RUBRIQUES = [
        [1, 'Frais d\'inscription', '710010', 50000, false],
        [2, 'Frais de bibliothèque', '770830', 35000, false],
        [3, 'Tenue de sport', '770820', 0, false],
        [4, 'Frais périscolaires', '770810', 50000, false],
        [5, 'Pension (frais de scolarité)', null, 1050000, true],
    ];

    public function up(): void
    {
        if (DB::table('rubriques_frais')->where('annee_academique', self::ANNEE)->where('niveau', self::NIVEAU)->exists()) {
            return;
        }
        $maintenant = now();
        DB::table('rubriques_frais')->insert(array_map(fn (array $r): array => [
            'annee_academique' => self::ANNEE, 'niveau' => self::NIVEAU, 'ordre' => $r[0], 'libelle' => $r[1], 'code_imputation' => $r[2],
            'montant' => $r[3], 'compte_selon_promotion' => $r[4], 'actif' => true, 'created_at' => $maintenant, 'updated_at' => $maintenant,
        ], self::RUBRIQUES));
    }

    public function down(): void
    {
        foreach (self::RUBRIQUES as [$ordre, $libelle, $code, $montant, $selonPromotion]) {
            DB::table('rubriques_frais')->where('annee_academique', self::ANNEE)->where('niveau', self::NIVEAU)->where('ordre', $ordre)
                ->where('libelle', $libelle)->where('montant', $montant)->where('compte_selon_promotion', $selonPromotion)
                ->where(fn ($q) => $code === null ? $q->whereNull('code_imputation') : $q->where('code_imputation', $code))
                ->delete();
        }
    }
};
