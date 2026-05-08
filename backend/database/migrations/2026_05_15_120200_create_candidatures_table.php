<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Table candidatures (modèle plat aligné spec v1.1).
 *
 * Notes structurelles :
 * - id          : auto-increment interne, jamais exposé en API publique.
 * - uuid        : identifiant exposé dans les URLs publiques /c/{uuid}.
 * - numero_dossier : référence administrative P{N}{YY}-{ID} (ex P14026-001).
 *   Généré atomiquement par CandidatureNumeroService (advisory lock par campagne).
 * - phone_e164  : login candidat. UNIQUE par campagne (pas global) — cf. correction
 *   PR A : un même candidat peut postuler à plusieurs campagnes successives.
 * - photo_path  : chemin relatif dans le bucket MinIO `pssfp-candidatures`.
 *   Jamais d'URL publique : tout téléchargement passe par URL signée 30 min côté API.
 * - nationalite : citoyenneté ISO-2 (distinct de pays_residence). RESTRICT en cas de
 *   suppression du pays référentiel — protège l'intégrité historique.
 * - region/departement : SET NULL si la subdivision est réorganisée (cas réel CM).
 *
 * FK ON DELETE :
 *   - campagne_id : RESTRICT (les campagnes ne se suppriment pas tant qu'il y a des dossiers).
 *   - user_id     : SET NULL (un compte user supprimé ne doit pas casser l'historique).
 *   - nationalite : RESTRICT.
 *   - region, departement : SET NULL.
 *   - pays_origine, pays_residence : RESTRICT (intégrité géographique).
 *
 * Source : docs/specs/module-5-candidatures.md (v1.1 §M3, §M5, §M6, §M9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidatures', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('numero_dossier', 30);

            // Liens
            $table->unsignedBigInteger('campagne_id');
            $table->unsignedBigInteger('user_id')->nullable();

            // Auth / contact
            $table->string('phone_e164', 20);
            $table->string('phone_country', 2);
            $table->string('email', 150)->nullable();

            // Identité (cf. spec §M6)
            $table->string('civilite', 10);
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('epouse', 100)->nullable();
            $table->date('date_naissance');
            $table->string('lieu_naissance', 100);
            $table->string('genre', 10);
            $table->string('statut_matrimonial', 20);

            // Géographie
            $table->string('nationalite', 2);
            $table->string('pays_origine', 2);
            $table->string('pays_residence', 2);
            $table->string('region', 30)->nullable();
            $table->string('departement', 50)->nullable();
            $table->string('adresse', 200);
            $table->string('ville_residence', 100);

            // Téléphones
            $table->string('indicatif1', 10);
            $table->string('telephone1', 20);
            $table->string('indicatif2', 10)->nullable();
            $table->string('telephone2', 20)->nullable();

            // Pédagogique
            $table->string('specialite', 100);
            $table->string('second_choix', 100)->nullable();
            $table->string('type_etude', 20);
            $table->string('premiere_langue', 5);
            $table->string('diplome_obtenu', 100);
            $table->string('institut', 150);
            $table->string('specialite_diplome', 100);
            $table->smallInteger('annee_diplome');
            $table->string('statut_actuel', 30);
            $table->string('employeur', 150)->nullable();
            $table->string('adresse_employeur', 200)->nullable();
            $table->string('tel_employeur', 30)->nullable();

            // Engagement / marketing
            $table->string('engagement_nom', 200);
            $table->string('moyen_connaissance', 50)->nullable();

            // Pièces (chemin relatif MinIO, cf. note structurelle ci-dessus).
            $table->string('photo_path', 255)->nullable();

            // Statuts métier (spec §M3) : postulant -> candidat -> accepte/refuse.
            $table->string('statut', 20)->default('postulant');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();

            // Frais (saisis par admin lors du dépôt physique)
            $table->boolean('frais_paye')->default(false);
            $table->string('mode_paiement', 30)->nullable();
            $table->string('reference_paiement', 100)->nullable();
            $table->date('date_paiement')->nullable();

            // PDF récépissé
            $table->string('recipisse_pdf_path', 255)->nullable();
            $table->string('recipisse_hash_sha256', 64)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            // FK
            $table->foreign('campagne_id')
                ->references('id')->on('campagnes_candidature')
                ->onDelete('restrict');
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('set null');
            $table->foreign('nationalite')
                ->references('code_iso')->on('pays')
                ->onDelete('restrict');
            $table->foreign('pays_origine')
                ->references('code_iso')->on('pays')
                ->onDelete('restrict');
            $table->foreign('pays_residence')
                ->references('code_iso')->on('pays')
                ->onDelete('restrict');
            $table->foreign('region')
                ->references('code')->on('regions_cameroun')
                ->onDelete('set null');
            $table->foreign('departement')
                ->references('code')->on('departements_cameroun')
                ->onDelete('set null');

            // Unicité
            $table->unique('numero_dossier', 'candidatures_numero_dossier_unique');
            $table->unique(['campagne_id', 'phone_e164'], 'candidatures_campagne_phone_unique');

            // Index lookup fréquents
            $table->index(['campagne_id', 'statut'], 'candidatures_campagne_statut_idx');
            $table->index('phone_e164', 'candidatures_phone_idx');
        });

        // Contraintes CHECK (Postgres) — équivalent enum.
        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_statut_check
            CHECK (statut IN ('postulant', 'candidat', 'accepte', 'refuse'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_type_etude_check
            CHECK (type_etude IN ('presentiel', 'distanciel'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_premiere_langue_check
            CHECK (premiere_langue IN ('fr', 'en'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_statut_actuel_check
            CHECK (statut_actuel IN ('Etudiant', 'Fonctionnaire-Contractuel', 'Prive'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('candidatures');
    }
};
