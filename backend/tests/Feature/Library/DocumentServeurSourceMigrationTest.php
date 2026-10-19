<?php

declare(strict_types=1);

use App\Models\Document;
use Illuminate\Database\QueryException;

uses()->group('module-3', 'library');

it('accepte la source serveur sans identifiant Joomla', function (): void {
    $doc = Document::factory()->create(['legacy_source' => 'serveur', 'legacy_id' => null]);

    expect($doc->fresh()->legacy_source)->toBe('serveur');
});

it('refuse une source inconnue', function (): void {
    expect(fn () => Document::factory()->create(['legacy_source' => 'autre']))->toThrow(QueryException::class);
});

it('refuse le rollback tant que des lignes serveur existent', function (): void {
    Document::factory()->create(['legacy_source' => 'serveur', 'legacy_id' => null]);
    $migration = require database_path('migrations/2026_10_19_100000_add_serveur_to_documents_legacy_source_check.php');

    expect(fn () => $migration->down())->toThrow(QueryException::class);
});

it('accepte le rollback quand aucune ligne serveur n\'existe', function (): void {
    $migration = require database_path('migrations/2026_10_19_100000_add_serveur_to_documents_legacy_source_check.php');
    $migration->down();

    expect(fn () => Document::factory()->create(['legacy_source' => 'serveur', 'legacy_id' => null]))->toThrow(QueryException::class);
});
