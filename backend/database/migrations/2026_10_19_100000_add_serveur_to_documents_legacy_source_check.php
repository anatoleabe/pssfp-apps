<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Plan 4 : les fichiers trouvés sur l'ancien serveur sans fiche Joomla portent la source « serveur ».
 * DB::statement : une contrainte CHECK n'a pas d'équivalent dans le Schema builder (cf. migration d'origine).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_legacy_source_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_legacy_source_check CHECK (legacy_source IS NULL OR legacy_source IN ('docman','alexandria','serveur'))");
    }

    /** Échoue s'il reste des lignes « serveur » : pas de perte silencieuse. */
    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_legacy_source_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_legacy_source_check CHECK (legacy_source IS NULL OR legacy_source IN ('docman','alexandria'))");
    }
};
