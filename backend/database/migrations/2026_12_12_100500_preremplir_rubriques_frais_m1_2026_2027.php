<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rubriques du M1 et du M2 2026-2027 (reçu A4, R2), d'après le plan comptable des produits de la RAF et la décision du chef de
 * projet du 16/11/2026 : en M1, les 50 000 FCFA restants des droits d'inscription sont la tenue de sport (le 0 du reçu ACMA
 * était une erreur de paramétrage d'ACMA). Pension au compte de la promotion.
 *
 * - M1 : inscription 50 000, bibliothèque 35 000, tenue de sport 50 000, périscolaires 50 000, pension 1 000 000 :
 *   1 185 000 FCFA, total du barème M1 (185 000 + 500 000 + 500 000).
 * - M2 : inscription 50 000, bibliothèque 35 000, tenue de sport 0 (ligne gardée), périscolaires 50 000, pension 1 000 000 :
 *   1 135 000 FCFA, total du barème M2 (135 000 + 500 000 + 500 000).
 *
 * N'écrit rien pour un couple qui a déjà des rubriques ; `down()` ne retire que des lignes restées telles quelles.
 */
return new class extends Migration
{
    private const ANNEE = '2026-2027';

    /** Niveau => [ordre, libellé, code d'imputation, montant, compte selon la promotion] */
    private const RUBRIQUES = [
        'M1' => [
            [1, 'Frais d\'inscription', '710010', 50000, false],
            [2, 'Frais de bibliothèque', '770830', 35000, false],
            [3, 'Tenue de sport', '770820', 50000, false],
            [4, 'Frais périscolaires', '770810', 50000, false],
            [5, 'Pension (frais de scolarité)', null, 1000000, true],
        ],
        'M2' => [
            [1, 'Frais d\'inscription', '710010', 50000, false],
            [2, 'Frais de bibliothèque', '770830', 35000, false],
            [3, 'Tenue de sport', '770820', 0, false],
            [4, 'Frais périscolaires', '770810', 50000, false],
            [5, 'Pension (frais de scolarité)', null, 1000000, true],
        ],
    ];

    public function up(): void
    {
        $maintenant = now();
        foreach (self::RUBRIQUES as $niveau => $rubriques) {
            if (DB::table('rubriques_frais')->where('annee_academique', self::ANNEE)->where('niveau', $niveau)->exists()) {
                continue;
            }
            DB::table('rubriques_frais')->insert(array_map(fn (array $r): array => [
                'annee_academique' => self::ANNEE, 'niveau' => $niveau, 'ordre' => $r[0], 'libelle' => $r[1], 'code_imputation' => $r[2],
                'montant' => $r[3], 'compte_selon_promotion' => $r[4], 'actif' => true, 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ], $rubriques));
        }
    }

    public function down(): void
    {
        foreach (self::RUBRIQUES as $niveau => $rubriques) {
            foreach ($rubriques as [$ordre, $libelle, $code, $montant, $selonPromotion]) {
                DB::table('rubriques_frais')->where('annee_academique', self::ANNEE)->where('niveau', $niveau)->where('ordre', $ordre)
                    ->where('libelle', $libelle)->where('montant', $montant)->where('compte_selon_promotion', $selonPromotion)
                    ->where(fn ($q) => $code === null ? $q->whereNull('code_imputation') : $q->where('code_imputation', $code))
                    ->delete();
            }
        }
    }
};
