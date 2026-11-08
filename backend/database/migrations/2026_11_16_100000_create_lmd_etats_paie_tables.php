<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Barème et états de paie mensuels des enseignants (spec module 4, lot 13b-1 ; D23, D24, D25 ; sql/16 sections 2 et 5).
 * La surveillance d'une évaluation devient une nature de pointage (réponse de la Scolarité du 08/11/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baremes_remuneration', function (Blueprint $table): void {
            $table->id();
            $table->string('annee_academique', 9);
            $table->string('nature', 24);
            $table->string('unite', 10)->default('heure');
            $table->unsignedInteger('montant_unitaire')->default(0);
            $table->decimal('taux_retenue', 5, 2)->default(0);
            $table->string('statut', 10)->default('propose');
            $table->string('reference_texte', 255)->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('valide_at')->nullable();
            $table->timestampsTz();

            $table->unique(['annee_academique', 'nature']);
            $table->index('valide_par');
        });

        Schema::create('etats_paie', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique();
            $table->date('periode_du');
            $table->date('periode_au');
            $table->string('objet', 255);
            $table->foreignId('rectificatif_de')->nullable()->constrained('etats_paie')->restrictOnDelete();
            $table->string('statut', 16)->default('brouillon');
            $table->jsonb('ecartes')->nullable();
            $table->foreignId('verifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verifie_at')->nullable();
            $table->foreignId('vise_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('vise_at')->nullable();
            $table->foreignId('transmis_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('transmis_at')->nullable();
            $table->string('reference_bordereau', 100)->nullable();
            $table->foreignId('pris_en_charge_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('pris_en_charge_at')->nullable();
            $table->date('paye_le')->nullable();
            $table->string('reference_paiement', 100)->nullable();
            $table->string('motif_rejet', 500)->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('periode_du');
            $table->index('rectificatif_de');
            foreach (['verifie_par', 'vise_par', 'transmis_par', 'pris_en_charge_par', 'cree_par'] as $c) {
                $table->index($c);
            }
        });

        Schema::create('lignes_etat_paie', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('etat_id')->constrained('etats_paie')->cascadeOnDelete();
            $table->foreignId('enseignant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('maquette_ecue_id')->nullable()->constrained('maquette_ecues')->restrictOnDelete();
            $table->string('nature', 24);
            $table->decimal('quantite', 10, 4);
            $table->unsignedInteger('montant_unitaire');
            $table->decimal('taux_retenue', 5, 2)->default(0);
            $table->unsignedBigInteger('montant_brut');
            $table->unsignedBigInteger('montant_retenue');
            $table->unsignedBigInteger('montant_net');
            $table->decimal('heures_hors_quota', 8, 2)->default(0);
            $table->string('observation', 500)->nullable();
            $table->timestampsTz();

            $table->index('etat_id');
            $table->index(['enseignant_id', 'maquette_ecue_id', 'nature']);
            $table->index('maquette_ecue_id');
        });

        Schema::table('pointages_enseignant', function (Blueprint $table): void {
            $table->string('nature', 12)->default('CM')->change();
            $table->foreign('ligne_paie_id')->references('id')->on('lignes_etat_paie')->nullOnDelete();
        });

        // SQL brut, ADR-0011 : valeurs fermées, montants cohérents, un seul état ordinaire par mois, circuit cohérent.
        DB::statement("ALTER TABLE baremes_remuneration ADD CONSTRAINT baremes_remuneration_check CHECK (nature IN ('CM', 'TD', 'TP', 'surveillance', 'prime_animation', 'encadrement_memoire', 'jury_soutenance', 'jury_deliberation', 'autre') AND unite IN ('heure', 'seance', 'forfait', 'memoire') AND statut IN ('propose', 'valide') AND taux_retenue BETWEEN 0 AND 100)");
        DB::statement("ALTER TABLE etats_paie ADD CONSTRAINT etats_paie_check CHECK (statut IN ('brouillon', 'verifie', 'vise', 'transmis', 'pris_en_charge', 'paye', 'rejete') AND periode_au >= periode_du AND (statut <> 'rejete' OR motif_rejet IS NOT NULL) AND (statut <> 'paye' OR (paye_le IS NOT NULL AND reference_paiement IS NOT NULL)) AND (statut NOT IN ('transmis', 'pris_en_charge', 'paye') OR reference_bordereau IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX etats_paie_un_par_mois ON etats_paie (periode_du) WHERE rectificatif_de IS NULL AND statut <> 'rejete'");
        DB::statement("ALTER TABLE lignes_etat_paie ADD CONSTRAINT lignes_etat_paie_check CHECK (quantite > 0 AND nature IN ('CM', 'TD', 'TP', 'surveillance', 'prime_animation') AND montant_net = montant_brut - montant_retenue)");
        DB::statement('ALTER TABLE pointages_enseignant DROP CONSTRAINT pointages_enseignant_valeurs_check');
        DB::statement("ALTER TABLE pointages_enseignant ADD CONSTRAINT pointages_enseignant_valeurs_check CHECK (mode IN ('signature', 'badge', 'qr_code', 'zoom', 'saisie') AND nature IN ('CM', 'TD', 'TP', 'surveillance') AND statut IN ('a_valider', 'valide', 'rejete') AND (depart IS NULL OR arrivee IS NULL OR depart > arrivee) AND (remplace_enseignant_id IS NULL OR remplace_enseignant_id <> enseignant_id))");

        // Barème 2026-2027 (D24, D25 ; reprise/25_calendrier_2026_2027.sql) ; surveillance à fixer (question 8 de la Scolarité).
        $maintenant = now();
        $ligne = fn (string $nature, string $unite, int $montant, float $taux, string $statut, string $ref): array => [
            'annee_academique' => '2026-2027', 'nature' => $nature, 'unite' => $unite, 'montant_unitaire' => $montant, 'taux_retenue' => $taux,
            'statut' => $statut, 'reference_texte' => $ref, 'valide_at' => $statut === 'valide' ? $maintenant : null, 'created_at' => $maintenant, 'updated_at' => $maintenant,
        ];
        DB::table('baremes_remuneration')->insert([
            $ligne('CM', 'heure', 17500, 11, 'valide', 'D24 et D25 du 29/10/2026 : 17 500 FCFA par heure de CM, retenue à la source de 11 %'),
            $ligne('TD', 'heure', 0, 0, 'valide', 'D24 : TD non rémunérés'),
            $ligne('TP', 'heure', 0, 0, 'valide', 'D24 : TP non rémunérés'),
            $ligne('prime_animation', 'forfait', 100000, 11, 'valide', 'D25 : 100 000 FCFA par cours, au prorata du taux de réalisation ; retenue de 11 %'),
            $ligne('surveillance', 'seance', 0, 11, 'propose', 'Réponse de la Scolarité du 08/11/2026 : surveillance payée au barème ; montant à fixer'),
        ]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pointages_enseignant DROP CONSTRAINT pointages_enseignant_valeurs_check');
        DB::table('pointages_enseignant')->where('nature', 'surveillance')->delete();
        Schema::table('pointages_enseignant', function (Blueprint $table): void {
            $table->dropForeign(['ligne_paie_id']);
            $table->string('nature', 2)->default('CM')->change();
        });
        DB::statement("ALTER TABLE pointages_enseignant ADD CONSTRAINT pointages_enseignant_valeurs_check CHECK (mode IN ('signature', 'badge', 'qr_code', 'zoom', 'saisie') AND nature IN ('CM', 'TD', 'TP') AND statut IN ('a_valider', 'valide', 'rejete') AND (depart IS NULL OR arrivee IS NULL OR depart > arrivee) AND (remplace_enseignant_id IS NULL OR remplace_enseignant_id <> enseignant_id))");
        Schema::dropIfExists('lignes_etat_paie');
        Schema::dropIfExists('etats_paie');
        Schema::dropIfExists('baremes_remuneration');
    }
};
