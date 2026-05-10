<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `assets` — bibliothèque média centralisée du site (cf. spec sprint S5 PR V).
 *
 * Stocke les références aux fichiers MinIO (logos, photos, documents).
 * Trois catégories racine : logo / photo / document.
 * Les fichiers sont sur les disks `minio_media` (logos/photos publics)
 * ou `minio_documents` (PDFs privés type catalogue, conventions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('filename', 200);
            $table->string('original_filename', 255);
            $table->string('category', 30)->index();
            $table->string('subcategory', 60)->nullable()->index();
            $table->string('mime', 100);
            $table->bigInteger('size')->unsigned();
            $table->string('disk', 30);
            $table->string('path', 300);
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->jsonb('alt')->nullable();
            $table->jsonb('description')->nullable();
            $table->jsonb('tags')->nullable();
            $table->jsonb('variants')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['disk', 'path']);
        });

        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_category_check CHECK (category IN ('logo','photo','document'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
