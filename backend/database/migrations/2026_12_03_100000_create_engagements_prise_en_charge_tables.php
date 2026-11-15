<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Engagements de prise en charge (spec module 4, lot A2 §9.2).
 *
 * - `engagements_prise_en_charge` : la lettre d'un organisme, sa portée, son année et son échéance.
 * - `reglements_organismes` : l'argent reçu de l'organisme, réparti en un versement par auditeur.
 * - `versements.reglement_organisme_id` : le versement issu d'un règlement, toujours payé par l'employeur ;
 *   figé après l'insertion par le déclencheur d'immuabilité des versements (D1), qui compare la ligne entière.
 * - `deblocages_acces.engagement_id` : une prise en charge passe toujours par un engagement, et elle seule.
 *
 * SQL brut limité aux CHECK et aux index partiels ou fonctionnels (précédents A1 et D1).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A1 refusait toute prise en charge : il n'en existe aucune, mais la contrainte l'exige.
        if (DB::table('deblocages_acces')->where('type', 'prise_en_charge')->exists()) {
            throw new RuntimeException('Des accès anticipés « prise en charge » existent sans engagement : reprenez-les à la main (moratoire) avant la migration.');
        }

        Schema::create('engagements_prise_en_charge', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('organisme', 200);
            $table->string('lettre_reference', 100);
            $table->date('lettre_date');
            $table->string('lettre_path')->unique();
            $table->string('contact_nom', 150)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_telephone', 30)->nullable();
            $table->string('portee', 20);
            $table->string('annee_academique', 9);
            $table->date('echeance');
            $table->text('notes')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('prolongations')->default(0);
            $table->unsignedSmallInteger('relances')->default(0);
            $table->timestampTz('derniere_relance_le')->nullable();
            $table->timestampsTz();

            $table->index('annee_academique');
            $table->index('echeance');
            $table->index('cree_par');
        });
        DB::statement("ALTER TABLE engagements_prise_en_charge ADD CONSTRAINT engagements_prise_en_charge_portee_check CHECK (portee IN ('droits_inscription', 'scolarite_annee'))");
        DB::statement("ALTER TABLE engagements_prise_en_charge ADD CONSTRAINT engagements_prise_en_charge_annee_check CHECK (annee_academique ~ '^[0-9]{4}-[0-9]{4}$')");
        // Une même lettre n'est pas saisie deux fois (double clic, deux agents).
        DB::statement('CREATE UNIQUE INDEX engagements_prise_en_charge_lettre_unique ON engagements_prise_en_charge (lower(organisme), lettre_reference, annee_academique)');

        Schema::create('reglements_organismes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('engagement_id')->constrained('engagements_prise_en_charge')->restrictOnDelete();
            $table->integer('montant');
            $table->date('date_reglement');
            $table->string('mode', 30);
            $table->string('reference', 60);
            $table->string('piece_path')->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('annule_at')->nullable();
            $table->foreignId('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('annule_motif', 500)->nullable();
            $table->timestampsTz();

            $table->index('engagement_id');
            $table->index('saisi_par');
            $table->index('annule_par');
        });
        DB::statement('ALTER TABLE reglements_organismes ADD CONSTRAINT reglements_organismes_montant_check CHECK (montant > 0)');
        DB::statement('ALTER TABLE reglements_organismes ADD CONSTRAINT reglements_organismes_annulation_check CHECK (
            (annule_at IS NULL AND annule_par IS NULL AND annule_motif IS NULL)
            OR (annule_at IS NOT NULL AND annule_motif IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX reglements_organismes_reference_unique ON reglements_organismes (mode, reference) WHERE annule_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX reglements_organismes_piece_unique ON reglements_organismes (piece_path) WHERE piece_path IS NOT NULL');

        Schema::table('versements', function (Blueprint $table): void {
            $table->foreignId('reglement_organisme_id')->nullable()->constrained('reglements_organismes')->restrictOnDelete();
            $table->index('reglement_organisme_id');
        });
        DB::statement("ALTER TABLE versements ADD CONSTRAINT versements_reglement_employeur_check CHECK (reglement_organisme_id IS NULL OR payeur = 'employeur')");

        Schema::table('deblocages_acces', function (Blueprint $table): void {
            $table->foreignId('engagement_id')->nullable()->constrained('engagements_prise_en_charge')->restrictOnDelete();
            $table->index('engagement_id');
        });
        DB::statement("ALTER TABLE deblocages_acces ADD CONSTRAINT deblocages_acces_engagement_check CHECK ((type = 'prise_en_charge') = (engagement_id IS NOT NULL))");
    }

    public function down(): void
    {
        // Le lien d'un auditeur à la lettre de son employeur, et l'argent reçu, sont de l'histoire : on ne les efface pas.
        if (Schema::hasTable('engagements_prise_en_charge') && DB::table('engagements_prise_en_charge')->exists()) {
            throw new RuntimeException('Des engagements de prise en charge sont enregistrés : revenez au code précédent sans annuler la migration.');
        }

        DB::statement('ALTER TABLE deblocages_acces DROP CONSTRAINT IF EXISTS deblocages_acces_engagement_check');
        Schema::table('deblocages_acces', function (Blueprint $table): void {
            $table->dropForeign(['engagement_id']);
            $table->dropIndex(['engagement_id']);
            $table->dropColumn('engagement_id');
        });
        // Supprimer une colonne ne déclenche pas l'immuabilité des versements (déclencheur de ligne).
        DB::statement('ALTER TABLE versements DROP CONSTRAINT IF EXISTS versements_reglement_employeur_check');
        Schema::table('versements', function (Blueprint $table): void {
            $table->dropForeign(['reglement_organisme_id']);
            $table->dropIndex(['reglement_organisme_id']);
            $table->dropColumn('reglement_organisme_id');
        });
        Schema::dropIfExists('reglements_organismes');
        Schema::dropIfExists('engagements_prise_en_charge');
    }
};
