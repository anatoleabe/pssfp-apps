<?php

declare(strict_types=1);

use App\Models\Document;
use Illuminate\Database\QueryException;

uses()->group('module-3', 'library');

it('retire le type rapport au rollback en repassant les lignes en bulletin', function (): void {
    $doc = Document::factory()->create(['type' => 'rapport']);

    $migration = require database_path('migrations/2026_10_18_110000_add_rapport_to_documents_type_check.php');
    $migration->down();

    expect($doc->fresh()->type)->toBe('bulletin');
    expect(fn () => Document::factory()->create(['type' => 'rapport']))->toThrow(QueryException::class);
});

it('rejoue up() sans effet de bord et accepte de nouveau rapport', function (): void {
    $migration = require database_path('migrations/2026_10_18_110000_add_rapport_to_documents_type_check.php');
    $migration->down();
    $migration->up();

    expect(Document::factory()->create(['type' => 'rapport'])->fresh()->type)->toBe('rapport');
    expect(fn () => Document::factory()->create(['type' => 'roman']))->toThrow(QueryException::class);
});
