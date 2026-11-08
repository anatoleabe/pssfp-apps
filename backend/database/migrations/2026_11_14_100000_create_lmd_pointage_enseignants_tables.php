<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Dossier de paie et pointage des cours enseignés (spec module 4, lot 13a ; D23, D24, sql/16 sections 1, 3 et 4).
 * Le NIU et le compte sont chiffrés par l'application (colonnes texte). La ligne d'état de paie (lot 13b) fige le pointage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers_paie_enseignant', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('enseignant_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('statut_paie', 14)->default('vacataire');
            $table->string('categorie', 20)->nullable();
            $table->decimal('service_du_heures', 6, 1)->nullable();
            $table->text('niu')->nullable();
            $table->string('banque', 120)->nullable();
            $table->text('compte_paiement')->nullable();
            $table->boolean('actif')->default(true);
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('cree_par');
        });

        Schema::create('pointages_enseignant', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('seance_id')->constrained('seances')->restrictOnDelete();
            $table->foreignId('enseignant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('remplace_enseignant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 16);
            $table->timestampTz('arrivee')->nullable();
            $table->timestampTz('depart')->nullable();
            $table->string('nature', 2)->default('CM');
            $table->text('contenu_enseigne')->nullable();
            $table->boolean('confirme_delegue')->nullable();
            $table->string('statut', 10)->default('a_valider');
            $table->unsignedSmallInteger('minutes_payables')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('valide_at')->nullable();
            $table->string('motif_rejet', 500)->nullable();
            $table->unsignedBigInteger('ligne_paie_id')->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['seance_id', 'enseignant_id']);
            $table->index(['enseignant_id', 'statut']);
            $table->index('remplace_enseignant_id');
            $table->index('valide_par');
            $table->index('ligne_paie_id');
            $table->index('saisi_par');
        });

        // SQL brut, ADR-0011 : états fermés, horaires cohérents, validation complète, rejet motivé.
        DB::statement("ALTER TABLE dossiers_paie_enseignant ADD CONSTRAINT dossiers_paie_enseignant_check CHECK (statut_paie IN ('permanent', 'vacataire', 'missionnaire', 'professionnel') AND (categorie IS NULL OR categorie IN ('professeur', 'maitre_conferences', 'charge_cours', 'assistant', 'docteur', 'expert')) AND (service_du_heures IS NULL OR service_du_heures >= 0))");
        DB::statement("ALTER TABLE pointages_enseignant ADD CONSTRAINT pointages_enseignant_valeurs_check CHECK (mode IN ('signature', 'badge', 'qr_code', 'zoom', 'saisie') AND nature IN ('CM', 'TD', 'TP') AND statut IN ('a_valider', 'valide', 'rejete') AND (depart IS NULL OR arrivee IS NULL OR depart > arrivee) AND (remplace_enseignant_id IS NULL OR remplace_enseignant_id <> enseignant_id))");
        DB::statement("ALTER TABLE pointages_enseignant ADD CONSTRAINT pointages_enseignant_validation_check CHECK (statut <> 'valide' OR (arrivee IS NOT NULL AND depart IS NOT NULL AND contenu_enseigne IS NOT NULL AND minutes_payables IS NOT NULL))");
        DB::statement("ALTER TABLE pointages_enseignant ADD CONSTRAINT pointages_enseignant_rejet_check CHECK (statut <> 'rejete' OR motif_rejet IS NOT NULL)");
        DB::statement("ALTER TABLE pointages_enseignant ADD CONSTRAINT pointages_enseignant_paie_check CHECK (ligne_paie_id IS NULL OR statut = 'valide')");
    }

    public function down(): void
    {
        Schema::dropIfExists('pointages_enseignant');
        Schema::dropIfExists('dossiers_paie_enseignant');
    }
};
