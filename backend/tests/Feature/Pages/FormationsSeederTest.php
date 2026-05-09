<?php

declare(strict_types=1);

use App\Models\Page;
use Database\Seeders\FormationsPagesSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('module-1', 'pages', 'formations');

beforeEach(function (): void {
    $this->seed([RolePermissionSeeder::class]);
});

it('FormationsPagesSeeder seeds the formations index page', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    $page = Page::query()->where('slug', 'formations')->first();
    expect($page)->not->toBeNull();
    expect($page->status)->toBe(Page::STATUS_PUBLISHED);
    expect($page->is_in_menu)->toBeTrue();
});

it('seeds 5 specialites under formations/specialites/*', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    $count = Page::query()->where('parent_slug', 'formations/specialites')->count();
    expect($count)->toBe(5);
});

it('seeds the admission and frais pages', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    expect(Page::query()->where('slug', 'formations/admission')->exists())->toBeTrue();
    expect(Page::query()->where('slug', 'formations/frais-de-scolarite')->exists())->toBeTrue();
});

it('is idempotent — running twice does not duplicate', function (): void {
    $this->seed(FormationsPagesSeeder::class);
    $countAfter1 = Page::query()->where(function ($q): void {
        $q->where('slug', 'formations')->orWhere('parent_slug', 'like', 'formations%');
    })->count();

    $this->seed(FormationsPagesSeeder::class);
    $countAfter2 = Page::query()->where(function ($q): void {
        $q->where('slug', 'formations')->orWhere('parent_slug', 'like', 'formations%');
    })->count();

    expect($countAfter2)->toBe($countAfter1);
});

it('GET /v1/pages/formations/specialites/fiscalite-finance-comptabilite-publique returns the page', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    $response = $this->getJson('/v1/pages/'.urlencode('formations/specialites/fiscalite-finance-comptabilite-publique'));

    $response->assertOk();
    $response->assertJsonPath('data.slug', 'formations/specialites/fiscalite-finance-comptabilite-publique');
    $response->assertJsonPath('data.parent_slug', 'formations/specialites');
});
