<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `articles` — actualités, billets, communiqués (cf. spec module 1 PR N).
 *
 * Compromis V1 : `category` stocké en TEXT (pas de table dédiée categories
 * pour V1 — extensible plus tard). `tags` non implémentés en V1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 100)->unique();
            $table->jsonb('title');
            $table->jsonb('excerpt')->nullable();
            $table->jsonb('body')->nullable();
            $table->string('featured_image_path', 200)->nullable();
            $table->string('category', 50)->nullable()->index();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->integer('views_count')->default(0);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['is_pinned', 'published_at']);
        });

        DB::statement("ALTER TABLE articles ADD CONSTRAINT articles_status_check CHECK (status IN ('draft','in_review','published','archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
