<?php

declare(strict_types=1);

use App\Models\Page;
use Database\Seeders\PssfpPagesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;

uses()->group('module-1', 'pages');

beforeEach(function (): void {
    $this->seed([RolePermissionSeeder::class]);
});

it('returns 404 when no page matches the slug', function (): void {
    $this->getJson('/v1/pages/inexistant')->assertStatus(404);
});

it('returns 404 when page exists but is in draft status', function (): void {
    Page::factory()->draft()->create(['slug' => 'pssfp/draft-only']);

    $this->getJson('/v1/pages/pssfp%2Fdraft-only')->assertStatus(404);
});

it('returns 200 with page payload when slug matches a published page', function (): void {
    Page::factory()->published()->create([
        'slug' => 'pssfp/presentation',
        'parent_slug' => 'pssfp',
        'title' => ['fr' => 'Présentation du PSSFP'],
        'body' => ['fr' => "## Notre mission\n\nFormer les cadres."],
    ]);

    $response = $this->getJson('/v1/pages/pssfp%2Fpresentation');

    $response->assertOk();
    $response->assertJsonPath('data.slug', 'pssfp/presentation');
    $response->assertJsonPath('data.title', 'Présentation du PSSFP');
    $response->assertJsonPath('data.body', "## Notre mission\n\nFormer les cadres.");
    $response->assertJsonPath('data.parent_slug', 'pssfp');
});

it('respects the published_at gate (future-dated pages are not visible)', function (): void {
    Page::factory()->create([
        'slug' => 'pssfp/future-page',
        'status' => Page::STATUS_PUBLISHED,
        'published_at' => now()->addDay(),
    ]);

    $this->getJson('/v1/pages/pssfp%2Ffuture-page')->assertStatus(404);
});

it('GET /v1/pages?parent_slug=pssfp returns only children of pssfp', function (): void {
    $this->seed(PssfpPagesSeeder::class);
    Page::factory()->published()->create(['slug' => 'foo', 'parent_slug' => null]);
    Page::factory()->published()->create(['slug' => 'bar/baz', 'parent_slug' => 'bar']);

    $response = $this->getJson('/v1/pages?parent_slug=pssfp');

    $response->assertOk();
    $slugs = collect($response->json('data'))->pluck('slug')->all();
    expect($slugs)->toContain('pssfp/presentation');
    expect($slugs)->toContain('pssfp/conformite-cames');
    expect($slugs)->not->toContain('foo');
    expect($slugs)->not->toContain('bar/baz');
});

it('GET /v1/pages?in_menu=true returns only menu pages sorted by order', function (): void {
    Page::factory()->published()->inMenu(30)->create(['slug' => 'p3']);
    Page::factory()->published()->inMenu(10)->create(['slug' => 'p1']);
    Page::factory()->published()->inMenu(20)->create(['slug' => 'p2']);
    // Une page hors menu — ne doit pas apparaître
    Page::factory()->published()->create(['slug' => 'hidden', 'is_in_menu' => false]);

    $response = $this->getJson('/v1/pages?in_menu=true');

    $response->assertOk();
    $slugs = collect($response->json('data'))->pluck('slug')->all();
    expect($slugs)->toBe(['p1', 'p2', 'p3']);
    expect($slugs)->not->toContain('hidden');
});

it('GET /v1/menu returns the nested menu structure (parent + children)', function (): void {
    Page::factory()->published()->inMenu(10, 'Le PSSFP')->create([
        'slug' => 'pssfp',
        'parent_slug' => null,
    ]);
    Page::factory()->published()->inMenu(10, 'Présentation')->create([
        'slug' => 'pssfp/presentation',
        'parent_slug' => 'pssfp',
    ]);
    Page::factory()->published()->inMenu(20, 'Mot du Président')->create([
        'slug' => 'pssfp/mot-president',
        'parent_slug' => 'pssfp',
    ]);

    $response = $this->getJson('/v1/menu');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['slug'])->toBe('pssfp');
    expect($data[0]['label'])->toBe('Le PSSFP');
    expect($data[0]['children'])->toHaveCount(2);
    expect($data[0]['children'][0]['label'])->toBe('Présentation');
    expect($data[0]['children'][1]['label'])->toBe('Mot du Président');
});

it('falls back to title when menu_label is null', function (): void {
    Page::factory()->published()->inMenu(10)->create([
        'slug' => 'no-label',
        'parent_slug' => null,
        'title' => ['fr' => 'Titre fallback'],
        'menu_label' => null,
    ]);

    $response = $this->getJson('/v1/menu');

    $response->assertOk();
    $data = $response->json('data');
    expect($data[0]['label'])->toBe('Titre fallback');
});

it('orphan menu children (parent absent) are surfaced at root level', function (): void {
    // Pas de page parent "ghost" en menu
    Page::factory()->published()->inMenu(10)->create([
        'slug' => 'orphan-child',
        'parent_slug' => 'ghost',
    ]);

    $response = $this->getJson('/v1/menu');

    $response->assertOk();
    $data = $response->json('data');
    $slugs = array_column($data, 'slug');
    expect($slugs)->toContain('orphan-child');
});

it('PssfpPagesSeeder seeds 8 pages all published and in_menu', function (): void {
    $this->seed(PssfpPagesSeeder::class);

    $count = Page::query()->where('parent_slug', 'pssfp')->count();
    expect($count)->toBe(8);

    $allPublished = Page::query()->where('parent_slug', 'pssfp')
        ->where('status', '!=', Page::STATUS_PUBLISHED)
        ->doesntExist();
    expect($allPublished)->toBeTrue();

    $allInMenu = Page::query()->where('parent_slug', 'pssfp')
        ->where('is_in_menu', false)
        ->doesntExist();
    expect($allInMenu)->toBeTrue();
});

it('PssfpPagesSeeder is idempotent — running twice does not duplicate', function (): void {
    $this->seed(PssfpPagesSeeder::class);
    $this->seed(PssfpPagesSeeder::class);

    expect(Page::query()->where('parent_slug', 'pssfp')->count())->toBe(8);
});

it('rejects unknown status values via CHECK constraint', function (): void {
    expect(fn () => Page::factory()->create(['status' => 'invalid_status']))
        ->toThrow(QueryException::class);
});
