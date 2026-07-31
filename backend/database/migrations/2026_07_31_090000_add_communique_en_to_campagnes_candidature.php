<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Version anglaise du communiqué conjoint.
 *
 * Colonne distincte plutôt que JSONB traduisible : il ne s'agit pas d'une
 * traduction de champ mais de deux fichiers signés distincts, chacun avec sa
 * propre existence juridique. L'un peut être publié sans l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->string('communique_pdf_path_en', 255)->nullable()->after('communique_pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->dropColumn('communique_pdf_path_en');
        });
    }
};
