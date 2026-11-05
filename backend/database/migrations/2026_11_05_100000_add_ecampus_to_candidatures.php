<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * État du compte eCampus de l'auditeur. NULL tant que rien n'a été tenté.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->string('ecampus_statut', 20)->nullable();
            $table->string('ecampus_detail', 255)->nullable();
            $table->timestampTz('ecampus_synchronise_at')->nullable();
            $table->index('ecampus_statut');
        });
    }

    public function down(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropIndex(['ecampus_statut']);
            $table->dropColumn(['ecampus_statut', 'ecampus_detail', 'ecampus_synchronise_at']);
        });
    }
};
