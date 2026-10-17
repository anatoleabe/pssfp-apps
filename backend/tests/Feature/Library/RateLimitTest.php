<?php

declare(strict_types=1);

use App\Models\Document;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

uses()->group('module-3', 'library');

beforeEach(fn () => Cache::flush());

it('branche les routes de la bibliothèque sur les limiteurs nommés', function (): void {
    $middleware = fn (string $name): array => Route::getRoutes()->getByName($name)->gatherMiddleware();

    expect($middleware('library.stats'))->toContain('throttle:library')
        ->and($middleware('library.search'))->toContain('throttle:library', 'throttle:library-search')
        ->and($middleware('library.documents.download'))->toContain('throttle:library', 'throttle:library-download')
        ->and($middleware('library.documents.view'))->toContain('throttle:library', 'throttle:library-view');
});

it('garde les plafonds par défaut 120 / 60 / 30 / 20', function (): void {
    expect(config('library.rate_limits'))->toBe([
        'library' => 120,
        'search' => 60,
        'download' => 30,
        'view' => 20,
    ])->and(config('library.rate_limit_exempt_ips'))->toBe([]);
});

it('limite les consultations à 20 par minute et par client', function (): void {
    $doc = Document::factory()->published()->create();

    foreach (range(1, 20) as $i) {
        $this->postJson("/v1/library/documents/{$doc->uuid}/view")->assertNoContent();
    }

    $this->postJson("/v1/library/documents/{$doc->uuid}/view")->assertStatus(429);
    $this->getJson('/v1/library/stats')->assertOk();
});

it('lit le plafond général dans la configuration', function (): void {
    config(['library.rate_limits.library' => 3]);

    foreach (range(1, 3) as $i) {
        $this->getJson('/v1/library/stats')->assertOk();
    }

    $this->getJson('/v1/library/stats')->assertStatus(429);
});

it('ne limite jamais une IP exemptée, et limite toujours les autres', function (): void {
    config([
        'library.rate_limits.library' => 3,
        'library.rate_limit_exempt_ips' => ['203.0.113.10'],
    ]);

    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson('/v1/library/stats')
            ->assertOk()
            ->assertHeaderMissing('X-RateLimit-Limit');
    }

    foreach (range(1, 3) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/v1/library/stats')->assertOk();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/v1/library/stats')->assertStatus(429);
});

it('lit la liste des IP exemptées depuis une variable séparée par des virgules', function (): void {
    putenv('LIBRARY_RATE_LIMIT_EXEMPT_IPS= 203.0.113.10 ,,198.51.100.7');
    $_ENV['LIBRARY_RATE_LIMIT_EXEMPT_IPS'] = $_SERVER['LIBRARY_RATE_LIMIT_EXEMPT_IPS'] = ' 203.0.113.10 ,,198.51.100.7';

    try {
        $config = require config_path('library.php');
    } finally {
        putenv('LIBRARY_RATE_LIMIT_EXEMPT_IPS');
        unset($_ENV['LIBRARY_RATE_LIMIT_EXEMPT_IPS'], $_SERVER['LIBRARY_RATE_LIMIT_EXEMPT_IPS']);
    }

    expect($config['rate_limit_exempt_ips'])->toBe(['203.0.113.10', '198.51.100.7']);
});

it('reprend le plafond par défaut si la variable est vide, non numérique ou nulle', function (string $value): void {
    $keys = ['LIBRARY_RATE_LIMIT', 'LIBRARY_SEARCH_RATE_LIMIT', 'LIBRARY_DOWNLOAD_RATE_LIMIT', 'LIBRARY_VIEW_RATE_LIMIT'];
    foreach ($keys as $key) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        $config = require config_path('library.php');
    } finally {
        foreach ($keys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    expect($config['rate_limits'])->toBe(['library' => 120, 'search' => 60, 'download' => 30, 'view' => 20]);
})->with(['vide' => '', 'texte' => 'abc', 'zéro' => '0', 'négatif' => '-5']);

it('applique un plafond valide lu dans l\'environnement', function (): void {
    putenv('LIBRARY_RATE_LIMIT=600');
    $_ENV['LIBRARY_RATE_LIMIT'] = $_SERVER['LIBRARY_RATE_LIMIT'] = '600';

    try {
        $config = require config_path('library.php');
    } finally {
        putenv('LIBRARY_RATE_LIMIT');
        unset($_ENV['LIBRARY_RATE_LIMIT'], $_SERVER['LIBRARY_RATE_LIMIT']);
    }

    expect($config['rate_limits']['library'])->toBe(600);
});
