<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table `pages` — pages institutionnelles éditables via Filament (cf. spec
 * module 1 PR K + data-model.md §pages).
 *
 * Compromis V1 vs spec :
 *  - `slug` reste TEXT UNIQUE global (cf. ADR-O2 — slug mono-locale, identique
 *    dans toutes les locales pour SEO).
 *  - `parent_slug` au lieu de `parent_id` (FK self) — simplifie la hiérarchie
 *    pour V1 sans contrainte FK auto-référentielle. Cohérence garantie au niveau
 *    applicatif (Filament refuse un parent inexistant).
 *  - `title`, `excerpt`, `body`, `meta_title`, `meta_description`, `menu_label`
 *    en JSONB (compat spatie/laravel-translatable) bien que seul `fr` soit
 *    activé en V1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 100)->unique();
            $table->string('parent_slug', 100)->nullable()->index();
            $table->jsonb('title');
            $table->jsonb('excerpt')->nullable();
            $table->jsonb('body')->nullable();
            $table->jsonb('meta_title')->nullable();
            $table->jsonb('meta_description')->nullable();
            $table->string('og_image_path', 200)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_in_menu')->default(false);
            $table->jsonb('menu_label')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['is_in_menu', 'order']);
        });

        DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_status_check CHECK (status IN ('draft','in_review','published','archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
