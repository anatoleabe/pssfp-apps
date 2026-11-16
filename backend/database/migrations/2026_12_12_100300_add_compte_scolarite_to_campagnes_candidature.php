<?php

declare(strict_types=1);

use App\Services\Scolarite\RubriquesFrais;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compte de scolarité de chaque promotion (plan comptable des produits de la RAF, reçu A4 R2) : la pension s'impute sur un
 * compte par promotion, pas par année ni par niveau. Champ nullable, modifiable par l'administrateur
 * (`finances.rubriques_gerer`). Pré-rempli pour les promotions existantes, 4 à 14, y compris les promotions antérieures
 * créées par la réinscription. Le compte 710100 (pénalité de retard des soutenances) n'est jamais un compte de promotion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->string('compte_scolarite', 12)->nullable();
        });
        DB::statement("ALTER TABLE campagnes_candidature ADD CONSTRAINT campagnes_candidature_compte_scolarite_check CHECK (
            compte_scolarite IS NULL OR (compte_scolarite ~ '^[0-9]{6}$' AND compte_scolarite <> '710100')
        )");
        // La table promotion => compte vit dans le code (RubriquesFrais::COMPTES_PROMOTIONS), appliquée aussi aux créations.
        foreach (RubriquesFrais::COMPTES_PROMOTIONS as $promotion => $compte) {
            DB::table('campagnes_candidature')->where('promotion_numero', $promotion)->whereNull('compte_scolarite')->update(['compte_scolarite' => $compte]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE campagnes_candidature DROP CONSTRAINT IF EXISTS campagnes_candidature_compte_scolarite_check');
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->dropColumn('compte_scolarite');
        });
    }
};
