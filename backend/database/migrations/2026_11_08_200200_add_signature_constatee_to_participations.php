<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Signature d'un membre sans compte (personnalité extérieure) : constatée sur le PV papier
 * par le responsable de la scolarité, et tracée (spec lot 6, § 3.9). Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participations_deliberation', function (Blueprint $table): void {
            $table->foreignId('signature_constatee_par')->nullable()->after('signe_at')->constrained('users')->nullOnDelete();
            $table->index('signature_constatee_par');
        });
    }

    public function down(): void
    {
        Schema::table('participations_deliberation', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signature_constatee_par');
        });
    }
};
