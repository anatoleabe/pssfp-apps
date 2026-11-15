<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accès anticipé (spec module 4, lot A1) : l'accès à eCampus ouvert avant le
 * paiement des droits d'inscription, sur un virement annoncé, une prise en
 * charge ou un moratoire. Un seul déblocage en cours par inscription ; un
 * virement annoncé porte toujours sa preuve. La colonne `engagement_id`
 * viendra avec le lot A2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deblocages_acces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->string('type', 20);
            $table->text('motif');
            $table->string('justificatif_path')->nullable();
            $table->date('echeance');
            $table->string('statut', 12)->default('en_cours');
            $table->foreignId('accorde_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('clos_le')->nullable();
            $table->foreignId('clos_par')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif_cloture')->nullable();
            // Prolongations accordées : l'agent n'en accorde qu'une sur un virement annoncé.
            $table->unsignedSmallInteger('prolongations')->default(0);
            $table->unsignedSmallInteger('relances')->default(0);
            $table->timestampTz('derniere_relance_le')->nullable();
            $table->timestampsTz();

            $table->index('inscription_annuelle_id');
            $table->index(['statut', 'echeance']);
            $table->index('accorde_par');
            $table->index('clos_par');
        });

        DB::statement("ALTER TABLE deblocages_acces ADD CONSTRAINT deblocages_acces_type_check CHECK (type IN ('virement_annonce', 'prise_en_charge', 'moratoire'))");
        DB::statement("ALTER TABLE deblocages_acces ADD CONSTRAINT deblocages_acces_statut_check CHECK (statut IN ('en_cours', 'regularise', 'revoque'))");
        DB::statement("ALTER TABLE deblocages_acces ADD CONSTRAINT deblocages_acces_preuve_virement_check CHECK (type <> 'virement_annonce' OR justificatif_path IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX deblocages_acces_un_en_cours ON deblocages_acces (inscription_annuelle_id) WHERE statut = 'en_cours'");
    }

    public function down(): void
    {
        Schema::dropIfExists('deblocages_acces');
    }
};
