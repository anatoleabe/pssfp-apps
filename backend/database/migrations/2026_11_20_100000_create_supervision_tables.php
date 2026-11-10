<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lot 14a (D30) : anomalies du contrôle interne et exécutions des contrôles. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies_systeme', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('controle_code', 40);
            $table->string('cle', 255);
            $table->jsonb('detail');
            $table->timestampTz('premiere_detection');
            $table->timestampTz('derniere_detection');
            $table->date('echeance');
            $table->string('statut', 16)->default('ouverte');
            $table->foreignId('assigne_a')->nullable()->constrained('users')->nullOnDelete();
            $table->string('justification', 1000)->nullable();
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('traite_le')->nullable();
            $table->boolean('fermee_auto')->default(false);
            $table->timestampsTz();

            $table->index(['statut', 'echeance']);
            $table->index('controle_code');
            $table->index('assigne_a');
            $table->index('traite_par');
        });

        Schema::create('executions_controles', function (Blueprint $table): void {
            $table->id();
            $table->string('controle_code', 40);
            $table->timestampTz('debut');
            $table->timestampTz('fin')->nullable();
            $table->unsignedInteger('trouves')->default(0);
            $table->unsignedInteger('nouveaux')->default(0);
            $table->unsignedInteger('corriges')->default(0);
            $table->string('erreur', 1000)->nullable();

            $table->index(['controle_code', 'debut']);
        });

        // SQL brut, ADR-0011 : états, justification obligatoire, une seule anomalie active par cas.
        DB::statement("ALTER TABLE anomalies_systeme ADD CONSTRAINT anomalies_systeme_check CHECK (
            statut IN ('ouverte', 'en_traitement', 'corrigee', 'justifiee')
            AND (statut <> 'justifiee' OR (justification IS NOT NULL AND length(trim(justification)) >= 10 AND traite_par IS NOT NULL))
        )");
        DB::statement("CREATE UNIQUE INDEX anomalies_systeme_active ON anomalies_systeme (controle_code, cle) WHERE statut IN ('ouverte', 'en_traitement', 'justifiee')");
    }

    public function down(): void
    {
        Schema::dropIfExists('executions_controles');
        Schema::dropIfExists('anomalies_systeme');
    }
};
