<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `candidature_documents` — pièces justificatives optionnelles du
 * dossier (LOT candidature P14, cf. communiqué officiel appel candidature).
 *
 * Optionnelles côté digital à dessein : le communiqué prévoit un dépôt
 * physique alternatif au bureau de la scolarité, donc aucune de ces pièces
 * ne bloque la soumission (cf. CandidatureService::checkSubmittable, qui ne
 * les référence pas dans $required — seule la photo identité reste
 * obligatoire, via candidatures.photo_path, inchangé).
 *
 * Plusieurs fichiers possibles par type (ex. relevés L1+L2+L3 en 3 uploads
 * distincts) — pas de contrainte d'unicité sur (candidature_id, type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidature_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('original_filename', 255);
            $table->string('mime', 100);
            $table->bigInteger('size')->unsigned();
            $table->string('path', 300);
            $table->timestamps();

            $table->index(['candidature_id', 'type']);
        });

        DB::statement(
            'ALTER TABLE candidature_documents ADD CONSTRAINT candidature_documents_type_check '.
            "CHECK (type IN ('diplome','acte_naissance','releves_notes','cv','lettre_motivation','attestation_employeur','autre'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('candidature_documents');
    }
};
