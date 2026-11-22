<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Note éliminatoire activable par année (décision du 22/11/2026) : l'interrupteur garde la valeur
 * quand on le coupe, au lieu de vider le champ. Les années sans note éliminatoire restent désactivées.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regles_evaluation', function (Blueprint $table): void {
            $table->boolean('note_eliminatoire_active')->default(true);
        });
        DB::table('regles_evaluation')->whereNull('note_plancher')->update(['note_eliminatoire_active' => false]);
    }

    public function down(): void
    {
        // Retour à l'ancienne convention : une note éliminatoire désactivée redevient un champ vide.
        DB::table('regles_evaluation')->where('note_eliminatoire_active', false)->update(['note_plancher' => null]);
        Schema::table('regles_evaluation', function (Blueprint $table): void {
            $table->dropColumn('note_eliminatoire_active');
        });
    }
};
