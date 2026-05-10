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

it('seeds 5 specialites under formations/specialites/* with new metiers-* slugs (S5 PR X)', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    $count = Page::query()->where('parent_slug', 'formations/specialites')->count();
    expect($count)->toBe(5);

    foreach (array_keys(FormationsPagesSeeder::SPECIALITES) as $slug) {
        expect(Page::query()->where('slug', 'formations/specialites/'.$slug)->exists())
            ->toBeTrue("Spécialité {$slug} doit être seedée");
    }
});

it('seeds 10 formation continue modules under formations/formation-continue/* (S5 PR X)', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    $count = Page::query()->where('parent_slug', 'formations/formation-continue')->count();
    expect($count)->toBe(10);

    foreach (array_keys(FormationsPagesSeeder::FORMATION_CONTINUE_MODULES) as $slug) {
        expect(Page::query()->where('slug', 'formations/formation-continue/'.$slug)->exists())
            ->toBeTrue("Module {$slug} doit être seedé");
    }
});

it('formation continue index page is in menu and references the 10 modules', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    $page = Page::query()->where('slug', 'formations/formation-continue')->first();
    expect($page)->not->toBeNull()
        ->and($page->is_in_menu)->toBeTrue();

    $body = $page->getTranslation('body', 'fr');
    foreach (array_keys(FormationsPagesSeeder::FORMATION_CONTINUE_MODULES) as $slug) {
        expect($body)->toContain($slug);
    }
});

it('seeds the master, certifications, seminaires, admission and frais pages (S5 PR X)', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    expect(Page::query()->where('slug', 'formations/master')->exists())->toBeTrue();
    expect(Page::query()->where('slug', 'formations/certifications')->exists())->toBeTrue();
    expect(Page::query()->where('slug', 'formations/seminaires')->exists())->toBeTrue();
    expect(Page::query()->where('slug', 'formations/admission')->exists())->toBeTrue();
    expect(Page::query()->where('slug', 'formations/frais-de-scolarite')->exists())->toBeTrue();
});

it('master page references the catalogue tariff 1 185 000 FCFA (not the old placeholder 750 000)', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    $page = Page::query()->where('slug', 'formations/master')->first();
    $body = $page->getTranslation('body', 'fr');
    expect($body)->toContain('1 185 000 FCFA')
        ->and($body)->not->toContain('750 000 FCFA');
});

it('formation continue page references the catalogue tariffs 4 995 000 / 500 000 / 250 000 FCFA', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    $page = Page::query()->where('slug', 'formations/formation-continue')->first();
    $body = $page->getTranslation('body', 'fr');
    expect($body)->toContain('4 995 000 FCFA')
        ->and($body)->toContain('500 000 FCFA')
        ->and($body)->toContain('250 000 FCFA');
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

it('GET /v1/pages/formations/specialites/metiers-fiscalite-comptabilite returns the page', function (): void {
    $this->seed(FormationsPagesSeeder::class);

    $response = $this->getJson('/v1/pages/'.urlencode('formations/specialites/metiers-fiscalite-comptabilite'));

    $response->assertOk();
    $response->assertJsonPath('data.slug', 'formations/specialites/metiers-fiscalite-comptabilite');
    $response->assertJsonPath('data.parent_slug', 'formations/specialites');
});
