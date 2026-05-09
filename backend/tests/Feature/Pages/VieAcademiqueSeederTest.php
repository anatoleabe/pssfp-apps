<?php

declare(strict_types=1);

use App\Models\Page;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VieAcademiquePagesSeeder;

uses()->group('module-1', 'pages', 'vie-academique');

beforeEach(function (): void {
    $this->seed([RolePermissionSeeder::class]);
});

it('seeds the vie-academique index page', function (): void {
    $this->seed(VieAcademiquePagesSeeder::class);

    $page = Page::query()->where('slug', 'vie-academique')->first();
    expect($page)->not->toBeNull();
    expect($page->is_in_menu)->toBeTrue();
});

it('seeds 6 child pages under vie-academique', function (): void {
    $this->seed(VieAcademiquePagesSeeder::class);

    $count = Page::query()->where('parent_slug', 'vie-academique')->count();
    expect($count)->toBe(6);
});

it('seeded pages are all published', function (): void {
    $this->seed(VieAcademiquePagesSeeder::class);

    $pending = Page::query()
        ->where(function ($q): void {
            $q->where('slug', 'vie-academique')->orWhere('parent_slug', 'vie-academique');
        })
        ->where('status', '!=', Page::STATUS_PUBLISHED)
        ->count();

    expect($pending)->toBe(0);
});

it('is idempotent when seeded twice', function (): void {
    $this->seed(VieAcademiquePagesSeeder::class);
    $this->seed(VieAcademiquePagesSeeder::class);

    expect(Page::query()->where('parent_slug', 'vie-academique')->count())->toBe(6);
});
