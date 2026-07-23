<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->string('fonction_actuelle', 150)->nullable()->after('statut_actuel');
            $table->string('moyen_connaissance_detail', 150)->nullable()->after('moyen_connaissance');
        });

        DB::statement('ALTER TABLE candidatures ALTER COLUMN moyen_connaissance TYPE VARCHAR(100)');

        DB::statement('ALTER TABLE candidatures DROP CONSTRAINT IF EXISTS candidatures_statut_actuel_check');
        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_statut_actuel_check
            CHECK (statut_actuel IN (
                'Etudiant', 'Sans-emploi', 'Fonctionnaire', 'Contractuel-Etat',
                'Etablissement-public', 'Entreprise-publique', 'Prive',
                'Independant', 'ONG-International', 'Autre',
                'Fonctionnaire-Contractuel'
            ))
        SQL);
    }

    public function down(): void
    {
        DB::table('candidatures')
            ->whereIn('statut_actuel', [
                'Sans-emploi', 'Fonctionnaire', 'Contractuel-Etat',
                'Etablissement-public', 'Entreprise-publique', 'Independant',
                'ONG-International', 'Autre',
            ])
            ->update(['statut_actuel' => 'Prive']);

        DB::statement('ALTER TABLE candidatures DROP CONSTRAINT IF EXISTS candidatures_statut_actuel_check');
        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_statut_actuel_check
            CHECK (statut_actuel IN ('Etudiant', 'Fonctionnaire-Contractuel', 'Prive'))
        SQL);

        DB::table('candidatures')
            ->whereRaw('char_length(moyen_connaissance) > 50')
            ->update(['moyen_connaissance' => null, 'moyen_connaissance_detail' => null]);
        DB::statement('ALTER TABLE candidatures ALTER COLUMN moyen_connaissance TYPE VARCHAR(50)');

        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropColumn(['fonction_actuelle', 'moyen_connaissance_detail']);
        });
    }
};
