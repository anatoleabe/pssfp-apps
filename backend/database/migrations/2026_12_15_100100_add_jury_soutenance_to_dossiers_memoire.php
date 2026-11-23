<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Jury de soutenance (spec lot 24a) : président, rapporteur (directeur de recherche) et examinateur du dossier
 * de mémoire. L'encadreur professionnel existe déjà. Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers_memoire', function (Blueprint $table): void {
            $table->string('jury_president', 255)->nullable();
            $table->string('jury_rapporteur', 255)->nullable();
            $table->string('jury_examinateur', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dossiers_memoire', function (Blueprint $table): void {
            $table->dropColumn(['jury_president', 'jury_rapporteur', 'jury_examinateur']);
        });
    }
};
