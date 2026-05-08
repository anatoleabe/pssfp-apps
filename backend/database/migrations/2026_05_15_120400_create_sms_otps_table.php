<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Table `sms_otps` — codes OTP à 6 chiffres pour la récupération PIN candidat
 * et vérification téléphone à venir (cf. ADR-0007 + spec module 5 §M13).
 *
 * Choix de schéma :
 * - Pas de FK vers users : un OTP peut être généré avant que le User existe
 *   (workflow verify_phone à l'inscription).
 * - code_hash : bcrypt via Hash::make. Comparaison via Hash::check.
 *   BCRYPT_ROUNDS=4 en testing (phpunit.xml) pour rester rapide.
 * - cancelled_at distinct de used_at : sémantique propre pour les statistiques.
 *   used_at = consommé avec succès, cancelled_at = invalidé administrativement
 *   (régénération, reset PIN réussi, etc.).
 * - Index (phone_e164, purpose, expires_at) pour la query de validation
 *   « dernier OTP non expiré non utilisé non annulé d'un phone+purpose ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_otps', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('phone_e164', 20);
            $table->string('code_hash', 100);
            $table->string('purpose', 30);
            $table->smallInteger('attempts')->default(0);
            $table->smallInteger('max_attempts')->default(3);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampsTz();

            $table->index(['phone_e164', 'purpose', 'expires_at'], 'sms_otps_lookup_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sms_otps
            ADD CONSTRAINT sms_otps_purpose_check
            CHECK (purpose IN ('reset_pin', 'verify_phone'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_otps');
    }
};
