<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Adapte la table `users` pour héberger les comptes candidats.
 *
 * Décisions PR B (cf. plan PR B + ADR-0007) :
 * - email devient NULLABLE : un candidat peut s'inscrire avec phone_e164 seul,
 *   sans email. L'unicité reste appliquée sur les valeurs non-NULL.
 * - phone_e164 + phone_country sont déjà ajoutés (migration 2026_05_06_051410).
 * - Aucune colonne `role` enum : on utilise exclusivement spatie/laravel-permission
 *   (assignRole, hasRole) — cf. ADR-0005 et arbitrage A du plan PR B.
 *
 * password_reset_tokens.email reste NOT NULL : il est lié au workflow
 * Fortify pour les utilisateurs avec email (admin/editor/etc.).
 * Les candidats utilisent un workflow OTP+PIN distinct (pas Fortify).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres : ALTER COLUMN ... DROP NOT NULL.
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');

        // L'index UNIQUE Postgres autorise déjà plusieurs NULL — pas de modif index nécessaire.
    }

    public function down(): void
    {
        // Restaure NOT NULL — n'est exécuté qu'en cas de rollback.
        // Si des candidats sans email existent en BDD, le rollback échouera explicitement.
        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
    }
};
