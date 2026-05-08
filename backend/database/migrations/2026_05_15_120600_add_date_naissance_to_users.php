<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ajoute users.date_naissance pour les comptes candidat.
 *
 * PR B `register` validait date_naissance pour PinService (rejet du PIN
 * = DDMMYY/YYMMDD) mais ne la stockait nulle part. PR C corrige : la donnée
 * est désormais persistée sur le User à l'inscription, ce qui permet de la
 * pré-remplir lors de la création de la Candidature draft sans demander au
 * candidat de la re-saisir.
 *
 * NULLABLE : les rôles non-candidat (admin, editor, ...) n'ont pas de
 * date de naissance dans le système.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('date_naissance')->nullable()->after('phone_country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('date_naissance');
        });
    }
};
