<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Documents délivrés et levées de restriction (spec module 4, lot 7 ; RG-059, D9).
 *
 * - Un document est numéroté, scellé (SHA-256 du PDF conservé) et vérifiable par un code.
 * - Il se révoque, il ne se modifie jamais : seules les colonnes de révocation changent.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents_delivres', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 30);
            $table->string('numero', 30)->unique();
            $table->string('code_verification', 24)->unique();
            $table->foreignId('auditeur_id')->constrained('auditeurs')->restrictOnDelete();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->foreignId('deliberation_id')->nullable()->constrained('deliberations')->restrictOnDelete();
            $table->string('titulaire', 255);
            $table->string('chemin', 255);
            $table->char('sha256', 64);
            $table->foreignId('delivre_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoque_at')->nullable();
            $table->string('revoque_motif', 500)->nullable();
            $table->foreignId('revoque_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['auditeur_id', 'type']);
            $table->index('inscription_annuelle_id');
            $table->index('deliberation_id');
            $table->index('delivre_par');
            $table->index('revoque_par');
        });

        Schema::create('levees_restriction', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->string('motif', 500);
            $table->foreignId('accordee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('inscription_annuelle_id');
            $table->index('accordee_par');
        });

        // SQL brut, ADR-0011 : types fermés, révocation motivée, une seule délivrance valide par objet.
        DB::statement("ALTER TABLE documents_delivres ADD CONSTRAINT documents_delivres_type_check CHECK (type IN ('releve_notes', 'attestation_reussite', 'certificat_scolarite'))");
        DB::statement('ALTER TABLE documents_delivres ADD CONSTRAINT documents_delivres_revocation_check CHECK (revoque_at IS NULL OR revoque_motif IS NOT NULL)');
        DB::statement('CREATE UNIQUE INDEX documents_delivres_valide_unique ON documents_delivres (type, inscription_annuelle_id, coalesce(deliberation_id, 0)) WHERE revoque_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('levees_restriction');
        Schema::dropIfExists('documents_delivres');
    }
};
