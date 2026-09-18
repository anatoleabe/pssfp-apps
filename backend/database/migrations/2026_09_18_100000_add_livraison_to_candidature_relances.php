<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi de livraison par message (cf. docs/specs/module-6-diagnostic-envois-2026-09.md).
 *
 * Trois colonnes nullables : les lignes antérieures à la bascule TechSoft
 * n'ont pas d'identifiant de message et gardent un statut de livraison
 * inconnu. Aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidature_relances', function (Blueprint $table): void {
            $table->string('message_uid', 64)->nullable();
            $table->string('statut_livraison', 32)->nullable();
            $table->string('cout', 20)->nullable();

            $table->index('message_uid', 'candidature_relances_message_uid_idx');
        });
    }

    public function down(): void
    {
        Schema::table('candidature_relances', function (Blueprint $table): void {
            $table->dropIndex('candidature_relances_message_uid_idx');
            $table->dropColumn(['message_uid', 'statut_livraison', 'cout']);
        });
    }
};
