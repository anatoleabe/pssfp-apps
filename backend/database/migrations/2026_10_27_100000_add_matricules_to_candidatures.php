<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Matricules des candidats retenus et identifiants Moodle initiaux.
 *
 * Attribués une fois, par une action de l'admin, puis figés : les exports
 * (fiche complète, import ACMA, comptes Moodle) relisent ces valeurs au lieu
 * de les recalculer, pour qu'un second export ne change ni un numéro ni un mot
 * de passe déjà communiqué.
 *
 * - rang_retenu : numéro d'ordre dans la promotion (ordre alphabétique à
 *   l'attribution), partagé par les catégories EFP et EFSC.
 * - categorie_matricule : EFP par défaut, EFSC sur décision d'un agent.
 * - moodle_password : chiffré par l'application (cast `encrypted`).
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->string('categorie_matricule', 10)->default('EFP');
            $table->string('matricule', 20)->nullable()->unique();
            $table->unsignedSmallInteger('rang_retenu')->nullable();
            $table->text('moodle_password')->nullable();
            $table->timestampTz('matricule_attribue_at')->nullable();

            $table->unique(['campagne_id', 'rang_retenu'], 'candidatures_campagne_rang_retenu_unique');
        });
    }

    public function down(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropUnique('candidatures_campagne_rang_retenu_unique');
            $table->dropUnique(['matricule']);
            $table->dropColumn(['categorie_matricule', 'matricule', 'rang_retenu', 'moodle_password', 'matricule_attribue_at']);
        });
    }
};
