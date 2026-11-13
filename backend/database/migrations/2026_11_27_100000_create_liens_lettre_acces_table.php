<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liens courts vers la lettre d'accès FOAD, envoyés par SMS à la place des
 * codes en clair. Seule l'empreinte du jeton est conservée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liens_lettre_acces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->char('empreinte', 64)->unique();
            $table->timestampTz('expire_le');
            $table->unsignedSmallInteger('telechargements')->default(0);
            $table->unsignedSmallInteger('echecs')->default(0);
            $table->timestampTz('revoque_le')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['candidature_id', 'revoque_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liens_lettre_acces');
    }
};
