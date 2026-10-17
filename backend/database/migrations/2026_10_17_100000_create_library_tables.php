<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domaine bibliothèque virtuelle (Module 3 V1).
 *
 * Écarts assumés avec data-model §8 : spécialité en code texte et promotion en
 * entier (pas de tables dédiées), auteurs sans lien vers des comptes, pas de
 * colonne OCR. Ajouts de l'audit Joomla : price_xaf, external_url,
 * ouvrage_numerique, legacy_source/legacy_id.
 */
return new class extends Migration
{
    private const TYPES = "'these','memoire','article_scientifique','texte_loi','cours','acte_conference','rapport_stage','bulletin','ressource_pedagogique','ouvrage_numerique'";

    public function up(): void
    {
        Schema::create('mots_cles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->jsonb('label');
            $table->foreignId('parent_id')->nullable()->constrained('mots_cles')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 160)->unique();
            $table->string('type', 30);
            $table->jsonb('title');
            $table->jsonb('subtitle')->nullable();
            $table->jsonb('abstract')->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('language', 5)->default('fr');
            $table->string('specialite', 120)->nullable();
            $table->smallInteger('promotion')->nullable();
            $table->string('journal', 200)->nullable();
            $table->string('doi', 120)->nullable();
            $table->string('isbn', 30)->nullable();
            $table->integer('pages_count')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->string('file_checksum_md5', 32)->nullable()->index();
            $table->string('cover_path', 255)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->string('access_level', 20)->default('public');
            $table->bigInteger('price_xaf')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->integer('views_count')->default(0);
            $table->integer('downloads_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('legacy_source', 20)->nullable();
            $table->integer('legacy_id')->nullable();
            $table->string('legacy_url', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['legacy_source', 'legacy_id']);
            $table->index(['type', 'published_at']);
            $table->index(['specialite', 'year']);
            $table->index('promotion');
            $table->index('access_level');
            $table->index(['status', 'published_at']);
        });

        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ('.self::TYPES.'))');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('draft','in_review','published','archived'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_access_level_check CHECK (access_level IN ('public','auditor','teacher','librarian'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_language_check CHECK (language IN ('fr','en','ar','autre'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_legacy_source_check CHECK (legacy_source IS NULL OR legacy_source IN ('docman','alexandria'))");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_price_check CHECK (price_xaf IS NULL OR price_xaf > 0)');
        // Fichier ou lien, exclusifs : un lien externe est toujours libre (spec V1 §4).
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_external_url_check CHECK (NOT (external_url IS NOT NULL AND (file_path IS NOT NULL OR price_xaf IS NOT NULL OR access_level <> 'public')))");

        Schema::create('document_authors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('author_name', 200);
            $table->string('author_affiliation', 200)->nullable();
            $table->smallInteger('order')->default(1);
            $table->timestamps();
        });

        Schema::create('document_mot_cle', function (Blueprint $table): void {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mot_cle_id')->constrained('mots_cles')->cascadeOnDelete();
            $table->primary(['document_id', 'mot_cle_id']);
        });

        Schema::create('document_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('downloaded_at')->useCurrent()->index();
        });

        Schema::create('search_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('query', 255);
            $table->jsonb('filters')->nullable();
            $table->integer('results_count');
            // Pas d'adresse IP : la finalité (recherches sans résultat) n'en a pas besoin.
            $table->timestampTz('searched_at')->useCurrent()->index();
        });

        Schema::create('library_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('from_path', 500)->unique();
            $table->string('to_path', 500);
            $table->smallInteger('status_code')->default(301);
            $table->integer('hits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_redirects');
        Schema::dropIfExists('search_logs');
        Schema::dropIfExists('document_downloads');
        Schema::dropIfExists('document_mot_cle');
        Schema::dropIfExists('document_authors');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('mots_cles');
    }
};
