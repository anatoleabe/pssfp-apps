<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cibles de redirection imposées en base (en plus du mutateur de LibraryRedirect) : un import en
 * masse (`insert()`/`upsert()`, Plan 3) ne peut pas enregistrer de cible piégée.
 * Mêmes règles que LibraryRedirect::isSafeTarget : commence par `/` sans être `//`, aucune barre
 * oblique inverse, aucun caractère de contrôle, aucun segment `.`/`..` (y compris `%2e`).
 * `from_path` est une clé de recherche : on exige seulement qu'elle commence par `/`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE library_redirects ADD CONSTRAINT library_redirects_to_path_check CHECK (
                to_path ~ '^/'
                AND to_path !~ '^//'
                AND strpos(to_path, E'\\') = 0
                AND to_path !~ '[[:cntrl:]]'
                AND to_path !~* '(^|/)(\.|%2e){1,2}([/?#]|$)'
            )
            SQL);
        DB::statement("ALTER TABLE library_redirects ADD CONSTRAINT library_redirects_from_path_check CHECK (from_path ~ '^/')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE library_redirects DROP CONSTRAINT IF EXISTS library_redirects_from_path_check');
        DB::statement('ALTER TABLE library_redirects DROP CONSTRAINT IF EXISTS library_redirects_to_path_check');
    }
};
