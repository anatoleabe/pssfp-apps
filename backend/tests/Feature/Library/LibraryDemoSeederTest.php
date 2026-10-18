<?php

declare(strict_types=1);

use Database\Seeders\LibraryDemoSeeder;

uses()->group('module-3', 'library');

it('refuse de s\'exécuter hors environnements local et testing', function (): void {
    $original = app()->environment();
    app()->detectEnvironment(fn () => 'staging');

    try {
        expect(fn () => (new LibraryDemoSeeder)->run())->toThrow(RuntimeException::class);
    } finally {
        app()->detectEnvironment(fn () => $original);
    }
});
