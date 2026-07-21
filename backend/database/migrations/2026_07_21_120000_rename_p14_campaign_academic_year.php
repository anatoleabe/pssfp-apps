<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mise à jour ciblée et non destructive : les dossiers gardent le même
        // campagne_id, seuls les libellés historiques connus sont renommés.
        DB::table('campagnes_candidature')
            ->where('slug', 'p14-2026')
            ->whereIn('nom', ['Promotion 14 — Campagne 2026', 'Campagne 2026'])
            ->update(['nom' => 'Année académique 2026-2027']);
    }

    public function down(): void
    {
        DB::table('campagnes_candidature')
            ->where('slug', 'p14-2026')
            ->where('nom', 'Année académique 2026-2027')
            ->update(['nom' => 'Promotion 14 — Campagne 2026']);
    }
};
