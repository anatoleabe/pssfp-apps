<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmation des versements par la scolarité (décision du 15/11/2026, spec
 * module-4-scolarite-encaissement-confirmation §3.2 et §3.3).
 *
 * Une table en ajout seul plutôt que des colonnes sur `versements` : le déclencheur de D1
 * (`versements_immuables`) n'autorise que l'annulation et le bordereau, et l'immuabilité de l'argent ne
 * s'élargit pas. Une ligne par versement (unique), jamais modifiée ni supprimée : une erreur se corrige
 * par l'annulation du versement. Les colonnes de vérification du reçu sont remplies à l'émission, figées
 * comme le reste du reçu (le déclencheur des reçus n'est pas touché).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmations_versements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('versement_id')->unique()->constrained('versements')->restrictOnDelete();
            $table->foreignId('confirme_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirme_at');
            $table->string('justificatif_path', 255)->nullable();
            $table->boolean('attestation')->default(false);
            $table->boolean('reprise')->default(false);
            $table->timestampTz('created_at')->useCurrent();
        });
        // Une confirmation faite à l'écran a toujours son auteur, son scan et son attestation ; seule la reprise s'en passe.
        DB::statement('ALTER TABLE confirmations_versements ADD CONSTRAINT confirmations_versements_completes CHECK (reprise OR (confirme_par IS NOT NULL AND justificatif_path IS NOT NULL AND attestation))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION confirmations_versements_immuables() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Confirmation de versement immuable : une erreur se corrige par l''annulation du versement' USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER confirmations_versements_immuables BEFORE UPDATE OR DELETE ON confirmations_versements
              FOR EACH ROW EXECUTE FUNCTION confirmations_versements_immuables();
            SQL);

        Schema::table('recus_versements', function (Blueprint $table): void {
            $table->string('verifie_par_nom', 255)->nullable();
            $table->date('verifie_le')->nullable();
        });
    }

    public function down(): void
    {
        // Le déclencheur des reçus refuse les UPDATE, pas les changements de structure.
        Schema::table('recus_versements', function (Blueprint $table): void {
            $table->dropColumn(['verifie_par_nom', 'verifie_le']);
        });
        Schema::dropIfExists('confirmations_versements');
        DB::unprepared('DROP FUNCTION IF EXISTS confirmations_versements_immuables()');
    }
};
