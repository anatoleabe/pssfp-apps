<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Actes produits à partir du calendrier (spec module 4, lot 12b ; D18, D19). Strictement additif.
 * Les neuf modèles du dossier source (reprise 25) sont livrés ici ; leurs délais restent modifiables.
 */
return new class extends Migration
{
    private const MODELES = [
        ['NS_JURY_EXAMENS', 'Note de service portant désignation du jury', 'examens', 'Le Chef de la structure', -10, false,
            "Le Chef de la structure,\n\nDécide :\n\nArticle 1er : Sont désignés membres du jury de {objet}, dont la séance se tiendra le {date_seance} :\n- Président : …\n- Rapporteur : …\n- Membres : …\n\nArticle 2 : La présente note de service sera enregistrée et communiquée partout où besoin sera."],
        ['NS_JURY_RATTRAPAGE', 'Note de service portant désignation du jury de rattrapage', 'rattrapage', 'Le Chef de la structure', -10, false,
            "Le Chef de la structure,\n\nDécide :\n\nArticle 1er : Sont désignés membres du jury de {objet}, dont la séance se tiendra le {date_seance} :\n- Président : …\n- Rapporteur : …\n- Membres : …\n\nArticle 2 : La présente note de service sera enregistrée et communiquée partout où besoin sera."],
        ['COMMUNIQUE_EXAMENS', 'Communiqué relatif aux examens', 'examens', 'Le Chef de la structure', -45, true,
            "Les auditeurs de la {promotion} ({niveau}) sont informés que les {evenement} se tiendront du {debut} au {fin}.\n\nLes modalités pratiques (salles, horaires, pièces à présenter) seront communiquées par voie d'affichage et dans l'espace auditeur."],
        ['COMMUNIQUE_RATTRAPAGE', 'Communiqué relatif aux examens de rattrapage', 'rattrapage', 'Le Chef de la structure', -25, true,
            "Les auditeurs concernés de la {promotion} ({niveau}) sont informés que les {evenement} se tiendront du {debut} au {fin}.\n\nSeuls les auditeurs admis à composer au rattrapage y prennent part."],
        ['PV_DELIBERATION', 'Procès-verbal de délibération', 'deliberation_rattrapage', 'Le Président du jury', 0, false,
            "Procès-verbal de la séance du jury du {date_seance} : {evenement}.\n\nLe procès-verbal détaillé est produit par le module des délibérations."],
        ['PV_SYNTHESE', 'Procès-verbal de synthèse', 'deliberation', 'Le Président du jury', 0, false,
            "Procès-verbal de synthèse de la séance du {date_seance} : {evenement}.\n\nLe procès-verbal détaillé est produit par le module des délibérations."],
        ['LISTE_RESULTATS', 'Liste des résultats publiés', 'publication', 'Le Chef de la structure', 0, false,
            "Liste des résultats : {evenement}, publiée le {debut}.\n\nLa liste nominative est produite par le module des résultats."],
        ['LISTE_ADMIS', 'Liste des admis', 'publication_admis', 'Le Chef de la structure', 0, false,
            "Liste des admis : {evenement}, publiée le {debut}.\n\nLa liste nominative est produite par le module des candidatures."],
        ['PROGRAMMATION_ENSEIGNANTS', 'Note de programmation des enseignements de la période', 'periode_cours', 'Le Chef de la structure', -15, false,
            "Programmation des enseignants pour la {evenement}, du {debut} au {fin} : cours, quotas horaires et fenêtres d'intervention.\n\nLe détail est joint à la présente note."],
    ];

    public function up(): void
    {
        Schema::create('modeles_acte', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('libelle', 255);
            $table->string('type_evenement', 30);
            $table->string('signataire', 255);
            $table->smallInteger('delai_jours');
            $table->text('gabarit');
            $table->boolean('diffusion_auditeurs')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestampsTz();

            $table->index('type_evenement');
        });

        Schema::create('actes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('modele_id')->constrained('modeles_acte')->restrictOnDelete();
            $table->foreignId('evenement_id')->constrained('evenements_calendrier')->restrictOnDelete();
            $table->string('objet', 500);
            $table->date('echeance');
            $table->text('contenu')->nullable();
            $table->string('statut', 12)->default('a_produire');
            $table->string('numero', 120)->nullable();
            $table->date('date_signature')->nullable();
            $table->string('signataire', 255)->nullable();
            $table->string('piece_signee_path')->nullable();
            $table->char('piece_signee_sha256', 64)->nullable();
            $table->string('motif', 500)->nullable();
            $table->foreignId('redige_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('redige_at')->nullable();
            $table->foreignId('soumis_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('soumis_at')->nullable();
            $table->foreignId('signature_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('signature_at')->nullable();
            $table->foreignId('diffuse_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('diffuse_at')->nullable();
            $table->timestampsTz();

            $table->unique(['evenement_id', 'modele_id']);
            $table->index('modele_id');
            $table->index(['statut', 'echeance']);
            $table->index('redige_par');
            $table->index('soumis_par');
            $table->index('signature_par');
            $table->index('diffuse_par');
        });

        DB::statement("ALTER TABLE actes ADD CONSTRAINT actes_check CHECK (statut IN ('a_produire', 'brouillon', 'soumis', 'signe', 'diffuse', 'annule') AND (statut NOT IN ('signe', 'diffuse') OR (numero IS NOT NULL AND date_signature IS NOT NULL AND signataire IS NOT NULL AND piece_signee_path IS NOT NULL)) AND (statut <> 'annule' OR motif IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX actes_numero_unique ON actes (numero) WHERE numero IS NOT NULL');

        $maintenant = now();
        DB::table('modeles_acte')->insert(array_map(fn (array $m): array => [
            'code' => $m[0], 'libelle' => $m[1], 'type_evenement' => $m[2], 'signataire' => $m[3], 'delai_jours' => $m[4],
            'diffusion_auditeurs' => $m[5], 'gabarit' => $m[6], 'actif' => true, 'created_at' => $maintenant, 'updated_at' => $maintenant,
        ], self::MODELES));
    }

    public function down(): void
    {
        Schema::dropIfExists('actes');
        Schema::dropIfExists('modeles_acte');
    }
};
