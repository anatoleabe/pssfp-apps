<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_e164', 20)->nullable()->unique()->after('email');
            $table->char('phone_country', 2)->nullable()->after('phone_e164');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone_e164']);
            $table->dropColumn(['phone_e164', 'phone_country']);
        });
    }
};
