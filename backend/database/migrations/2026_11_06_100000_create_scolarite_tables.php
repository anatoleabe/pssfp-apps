<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Scolarité des auditeurs (spec module 4, lot 1 ; ADR-0010).
 *
 * - Le matricule et le mot de passe FOAD restent sur la candidature.
 * - Une inscription copie les montants du barème (une modification ne touche
 *   pas les inscrits) mais lit ses échéances en direct, via `bareme_id`.
 * - Un versement n'est jamais supprimé : les clés étrangères sont en RESTRICT.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baremes_scolarite', function (Blueprint $table): void {
            $table->id();
            $table->string('annee_academique', 9);
            $table->string('niveau', 2);
            $table->unsignedInteger('montant_inscription');
            $table->unsignedInteger('montant_tranche1');
            $table->unsignedInteger('montant_tranche2');
            $table->string('inscription_echeance', 120)->nullable();
            $table->date('tranche1_echeance')->nullable();
            $table->date('tranche2_echeance')->nullable();
            $table->timestampsTz();

            $table->unique(['annee_academique', 'niveau']);
        });

        Schema::create('auditeurs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('candidature_id')->unique()->constrained('candidatures')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('inscriptions_annuelles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('auditeur_id')->constrained('auditeurs')->restrictOnDelete();
            $table->foreignId('bareme_id')->constrained('baremes_scolarite')->restrictOnDelete();
            $table->string('annee_academique', 9);
            $table->string('niveau', 2);
            $table->unsignedInteger('montant_inscription');
            $table->unsignedInteger('montant_tranche1');
            $table->unsignedInteger('montant_tranche2');
            $table->unsignedInteger('remise_montant')->default(0);
            $table->string('remise_motif', 500)->nullable();
            $table->foreignId('remise_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('remise_at')->nullable();
            $table->timestampTz('acces_notifie_at')->nullable();
            $table->timestampsTz();

            $table->unique(['auditeur_id', 'annee_academique']);
            $table->index('remise_par');
        });

        Schema::create('versements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->unsignedInteger('montant');
            $table->date('date_versement');
            $table->string('mode', 30);
            $table->string('reference', 100);
            $table->string('payeur', 20);
            $table->string('justificatif_path')->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('annule_at')->nullable();
            $table->foreignId('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('annule_motif', 500)->nullable();
            $table->timestampsTz();

            $table->index('inscription_annuelle_id');
            $table->index('saisi_par');
            $table->index('annule_par');
        });

        // SQL brut autorisé par l'ADR-0010 : Eloquent n'exprime pas un index partiel.
        // Une référence redevient utilisable quand son versement est annulé.
        DB::statement('CREATE UNIQUE INDEX versements_mode_reference_actifs_unique ON versements (mode, reference) WHERE annule_at IS NULL');

        // Garde-fous sur l'argent, même pour une écriture hors RegistreVersements
        // (import, console) : PostgreSQL ignore `unsigned`. SQL brut, ADR-0010.
        DB::statement('ALTER TABLE baremes_scolarite ADD CONSTRAINT baremes_scolarite_montants_check CHECK (montant_inscription >= 0 AND montant_tranche1 >= 0 AND montant_tranche2 >= 0)');
        DB::statement("ALTER TABLE baremes_scolarite ADD CONSTRAINT baremes_scolarite_niveau_check CHECK (niveau IN ('M1', 'M2'))");
        DB::statement('ALTER TABLE inscriptions_annuelles ADD CONSTRAINT inscriptions_annuelles_montants_check CHECK (montant_inscription >= 0 AND montant_tranche1 >= 0 AND montant_tranche2 >= 0 AND remise_montant >= 0 AND remise_montant <= montant_inscription + montant_tranche1 + montant_tranche2)');
        DB::statement('ALTER TABLE versements ADD CONSTRAINT versements_montant_check CHECK (montant > 0)');
    }

    public function down(): void
    {
        // Le retour arrière se fait par le code : ne jamais effacer des versements saisis.
        if (Schema::hasTable('versements') && DB::table('versements')->where('mode', '!=', 'reprise')->exists()) {
            throw new RuntimeException('Des versements ont été saisis : la table ne se supprime pas. Revenez au code précédent sans annuler la migration.');
        }
        Schema::dropIfExists('versements');
        Schema::dropIfExists('inscriptions_annuelles');
        Schema::dropIfExists('auditeurs');
        Schema::dropIfExists('baremes_scolarite');
    }
};
