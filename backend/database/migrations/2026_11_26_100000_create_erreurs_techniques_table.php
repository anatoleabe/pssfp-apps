<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Journal des erreurs techniques (API, back-office, tâches de fond, portail apply), lu dans l'administration. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erreurs_techniques', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 20);
            $table->string('type', 150)->nullable();
            $table->text('message');
            $table->string('emplacement', 300)->nullable();
            $table->string('methode', 10)->nullable();
            $table->string('page', 500)->nullable();
            $table->string('digest', 64)->nullable();
            $table->string('requete', 64)->nullable();
            $table->string('navigateur', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('created_at');
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erreurs_techniques');
    }
};
