<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Mémoires des autorisés à soutenir et attestation d'autorisation de dépôt
 * (spec module 4, lot 11 ; D32 à D34 ; RG-061). Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers_memoire', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('auditeur_id')->constrained('auditeurs')->restrictOnDelete();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->foreignId('deliberation_id')->nullable()->constrained('deliberations')->restrictOnDelete();
            $table->string('theme', 500)->nullable();
            $table->string('structure_accueil', 255)->nullable();
            $table->date('stage_debut')->nullable();
            $table->date('stage_fin')->nullable();
            $table->string('encadreur_academique', 255)->nullable();
            $table->foreignId('encadreur_academique_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('encadreur_professionnel', 255)->nullable();
            $table->string('statut', 14)->default('en_redaction');
            $table->string('statut_motif', 500)->nullable();
            $table->unsignedSmallInteger('vague')->nullable();
            $table->date('date_soutenance')->nullable();
            $table->unsignedSmallInteger('avancement_estime')->nullable();
            $table->timestampsTz();

            $table->unique(['auditeur_id', 'inscription_annuelle_id']);
            $table->index('inscription_annuelle_id');
            $table->index('deliberation_id');
            $table->index('encadreur_academique_id');
        });

        Schema::create('etapes_memoire', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_memoire_id')->constrained('dossiers_memoire')->restrictOnDelete();
            $table->string('etape', 20);
            $table->unsignedSmallInteger('ordre');
            $table->unsignedSmallInteger('poids');
            $table->date('date_prevue')->nullable();
            $table->string('statut', 10)->default('a_faire');
            $table->timestampTz('realisee_at')->nullable();
            $table->decimal('note', 5, 2)->nullable();
            $table->string('appreciation', 1000)->nullable();
            $table->string('document_path')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->foreignId('evaluee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('retard_autorise_at')->nullable();
            $table->foreignId('retard_autorise_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('retard_motif', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['dossier_memoire_id', 'etape']);
            $table->index('evaluee_par');
            $table->index('retard_autorise_par');
        });

        Schema::create('suivis_memoire', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_memoire_id')->constrained('dossiers_memoire')->restrictOnDelete();
            $table->date('date_suivi');
            $table->string('mode', 20);
            $table->unsignedSmallInteger('avancement_estime')->nullable();
            $table->string('chapitres', 500)->nullable();
            $table->string('observations', 2000)->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('dossier_memoire_id');
            $table->index('auteur_id');
        });

        Schema::create('avis_memoire', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_memoire_id')->constrained('dossiers_memoire')->restrictOnDelete();
            $table->string('encadreur', 14);
            $table->boolean('favorable');
            $table->string('commentaire', 1000)->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('dossier_memoire_id');
            $table->index('auteur_id');
        });

        Schema::create('rapports_similitude', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_memoire_id')->constrained('dossiers_memoire')->restrictOnDelete();
            $table->string('outil', 120);
            $table->decimal('taux', 5, 2);
            $table->date('date_rapport');
            $table->char('sha256', 64)->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('dossier_memoire_id');
            $table->index('auteur_id');
        });

        // SQL brut, ADR-0011 : états fermés, poids et notes bornés ; nouveau type de document.
        DB::statement("ALTER TABLE dossiers_memoire ADD CONSTRAINT dossiers_memoire_statut_check CHECK (statut IN ('en_redaction', 'pret', 'soutenu', 'suspendu', 'abandon') AND (statut NOT IN ('suspendu', 'abandon') OR statut_motif IS NOT NULL))");
        DB::statement("ALTER TABLE etapes_memoire ADD CONSTRAINT etapes_memoire_valeurs_check CHECK (etape IN ('debut', 'cadrage', 'depot_projet', 'presoutenance1', 'presoutenance2', 'depot_final', 'soutenance') AND statut IN ('a_faire', 'depose', 'valide', 'ajourne', 'dispense') AND poids <= 100 AND (note IS NULL OR note BETWEEN 0 AND 20))");
        DB::statement('ALTER TABLE etapes_memoire ADD CONSTRAINT etapes_memoire_retard_check CHECK (retard_autorise_at IS NULL OR retard_motif IS NOT NULL)');
        DB::statement("ALTER TABLE avis_memoire ADD CONSTRAINT avis_memoire_encadreur_check CHECK (encadreur IN ('academique', 'professionnel'))");
        DB::statement('ALTER TABLE rapports_similitude ADD CONSTRAINT rapports_similitude_taux_check CHECK (taux BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE documents_delivres DROP CONSTRAINT documents_delivres_type_check');
        DB::statement("ALTER TABLE documents_delivres ADD CONSTRAINT documents_delivres_type_check CHECK (type IN ('releve_notes', 'attestation_reussite', 'certificat_scolarite', 'autorisation_depot'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents_delivres DROP CONSTRAINT documents_delivres_type_check');
        DB::statement("ALTER TABLE documents_delivres ADD CONSTRAINT documents_delivres_type_check CHECK (type IN ('releve_notes', 'attestation_reussite', 'certificat_scolarite'))");
        Schema::dropIfExists('rapports_similitude');
        Schema::dropIfExists('avis_memoire');
        Schema::dropIfExists('suivis_memoire');
        Schema::dropIfExists('etapes_memoire');
        Schema::dropIfExists('dossiers_memoire');
    }
};
