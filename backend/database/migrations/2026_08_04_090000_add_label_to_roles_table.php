<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libellé lisible des rôles.
 *
 * `roles.name` reste l'identifiant technique utilisé par `hasRole()` et les
 * seeders ; `label` porte le nom affiché dans l'admin (« Comité d'admission »
 * plutôt que `admission_committee`) et la description du périmètre du rôle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('label', 150)->nullable()->after('name');
            $table->string('description', 255)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['label', 'description']);
        });
    }
};
