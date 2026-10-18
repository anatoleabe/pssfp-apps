<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute le type « Rapport et publication » (`rapport`) à la contrainte documents_type_check.
 * La migration d'origine (déployée) n'est pas modifiée : on remplace la contrainte.
 * down() : les lignes `rapport` repassent en `bulletin` avant de restaurer l'ancienne contrainte.
 */
return new class extends Migration
{
    private const OLD_TYPES = "'these','memoire','article_scientifique','texte_loi','cours','acte_conference','rapport_stage','bulletin','ressource_pedagogique','ouvrage_numerique'";

    private const NEW_TYPES = self::OLD_TYPES.",'rapport'";

    public function up(): void
    {
        $this->replaceConstraint(self::NEW_TYPES);
    }

    public function down(): void
    {
        DB::statement("UPDATE documents SET type = 'bulletin' WHERE type = 'rapport'");
        $this->replaceConstraint(self::OLD_TYPES);
    }

    private function replaceConstraint(string $types): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_check');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ('.$types.'))');
    }
};
