<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 15c (spec du lot 15, §5.4, §5.5, §7.2, §7.7, §7.9 ; ADR-0014) : barèmes, paiements, soutenances et documents
 * d'ACMA dans l'archive. Montants entiers en FCFA. Mêmes protections que l'archive académique : lecture seule hors du
 * mode reprise, `TRUNCATE` refusé, gel définitif (fonction `acma_archive_lecture_seule`, lot 15b).
 */
return new class extends Migration
{
    private const TABLES = ['acma_baremes', 'acma_paiements', 'acma_soutenances', 'acma_documents'];

    public function up(): void
    {
        Schema::create('acma_baremes', function (Blueprint $table): void {
            $table->id();
            $table->string('cle_source', 30)->unique();
            $table->string('annee', 9);
            $table->string('niveau_acma', 10);
            $table->string('niveau', 2);
            // Dû de référence : montantAregler (§3.6). Composantes telles qu'ACMA les nomme (tranche2 = bibliothèque, tranche3 = périscolaire).
            $table->bigInteger('du_total');
            $table->bigInteger('inscription')->nullable();
            $table->bigInteger('tranche1')->nullable();
            $table->bigInteger('tranche2')->nullable();
            $table->bigInteger('tranche3')->nullable();
            $table->bigInteger('tenue_sport')->nullable();
            $table->jsonb('categories');
            $table->boolean('heterogene')->default(false);
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->unique(['annee', 'niveau_acma']);
        });

        Schema::create('acma_paiements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_acma')->unique();
            $table->foreignId('dossier_id')->constrained('acma_dossiers')->restrictOnDelete();
            $table->string('annee', 9);
            $table->string('niveau_acma', 10);
            $table->string('niveau', 2);
            $table->string('phase', 50)->nullable();
            $table->bigInteger('montant_scolarite');
            $table->bigInteger('inscriptions');
            $table->bigInteger('bibliotheque');
            $table->bigInteger('fraisperiscol');
            $table->bigInteger('tenuesport');
            $table->bigInteger('total');
            $table->date('date_recu')->nullable();
            $table->string('date_recu_source', 30)->nullable();
            $table->string('numero_recu', 45)->nullable();
            $table->string('banque', 45)->nullable();
            $table->string('saisi_par_acma', 40)->nullable();
            $table->boolean('negatif')->default(false);
            $table->unsignedBigInteger('contrepartie_id_acma')->nullable();
            $table->boolean('total_nul')->default(false);
            $table->boolean('date_aberrante')->default(false);
            $table->boolean('hors_annee')->default(false);
            $table->boolean('doublon_exact')->default(false);
            $table->boolean('niveau_sans_inscription')->default(false);
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->index('dossier_id');
            $table->index(['annee', 'niveau_acma']);
        });

        Schema::create('acma_soutenances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_acma')->unique();
            $table->foreignId('dossier_id')->constrained('acma_dossiers')->restrictOnDelete();
            $table->string('niveau_acma', 10)->nullable();
            $table->string('niveau', 2)->nullable();
            $table->date('date')->nullable();
            $table->string('date_source', 45)->nullable();
            $table->string('date_origine', 10)->nullable();
            $table->text('theme')->nullable();
            $table->double('note')->nullable();
            $table->string('mention', 20)->nullable();
            $table->string('mention_source', 20)->nullable();
            $table->text('president')->nullable();
            $table->text('rapporteur')->nullable();
            $table->jsonb('membres')->nullable();
            $table->text('observation')->nullable();
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->index('dossier_id');
        });

        Schema::create('acma_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('cle_source', 40)->unique();
            $table->string('type', 25);
            $table->unsignedBigInteger('id_acma');
            $table->foreignId('dossier_id')->constrained('acma_dossiers')->restrictOnDelete();
            $table->string('niveau_acma', 10)->nullable();
            $table->string('niveau', 2)->nullable();
            $table->date('date')->nullable();
            $table->string('date_source', 45)->nullable();
            $table->jsonb('details')->nullable();
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->unique(['type', 'id_acma']);
            $table->index('dossier_id');
        });

        $annee = "~ '^[0-9]{4}-[0-9]{4}$'";
        $checks = [
            'acma_baremes' => ['annee' => "annee {$annee}", 'niveau' => "niveau IN ('M1', 'M2')", 'du' => 'du_total >= 0'],
            'acma_paiements' => [
                'annee' => "annee {$annee}",
                'niveau' => "niveau IN ('M1', 'M2')",
                'total' => 'total = montant_scolarite + inscriptions + bibliotheque + fraisperiscol + tenuesport',
                'drapeaux' => 'negatif = (least(montant_scolarite, inscriptions, bibliotheque, fraisperiscol, tenuesport) < 0) AND total_nul = (total = 0)',
            ],
            'acma_soutenances' => [
                'niveau' => "niveau IS NULL OR niveau IN ('M1', 'M2')",
                'origine' => "date_origine IS NULL OR date_origine IN ('texte', 'operation')",
                'date' => '(date IS NULL) = (date_origine IS NULL)',
            ],
            'acma_documents' => [
                'type' => "type IN ('certificat', 'attestation_reussite', 'lettre_stage', 'non_perception_bourse', 'mise_a_disposition')",
                'niveau' => "niveau IS NULL OR niveau IN ('M1', 'M2')",
            ],
        ];
        foreach ($checks as $table => $contraintes) {
            foreach ($contraintes as $nom => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$nom}_check CHECK ({$expression})");
            }
        }
        foreach (self::TABLES as $table) {
            DB::statement("CREATE TRIGGER {$table}_lecture_seule BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION acma_archive_lecture_seule()");
            DB::statement("CREATE TRIGGER {$table}_sans_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION acma_archive_lecture_seule()");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('acma_reprises') && DB::table('acma_reprises')->where('statut', 'gelee')->exists()) {
            throw new RuntimeException('Archive ACMA gelée : défaire ce lot demande une migration motivée (spec du lot 15, §7.3).');
        }
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_lecture_seule ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_sans_truncate ON {$table}");
            Schema::dropIfExists($table);
        }
    }
};
