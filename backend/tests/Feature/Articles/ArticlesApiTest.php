<?php

declare(strict_types=1);

use App\Models\Article;
use Database\Seeders\ArticlesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;

uses()->group('module-1', 'articles');

beforeEach(function (): void {
    $this->seed([RolePermissionSeeder::class]);
});

it('GET /v1/articles returns paginated published articles by default', function (): void {
    Article::factory()->count(5)->create(['status' => 'published', 'published_at' => now()->subDay()]);
    Article::factory()->count(3)->create(['status' => 'draft']);

    $response = $this->getJson('/v1/articles');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(5);
    expect($response->json('meta.total'))->toBe(5);
});

it('paginates 9 per page by default', function (): void {
    Article::factory()->count(15)->create(['status' => 'published', 'published_at' => now()->subDay()]);

    $response = $this->getJson('/v1/articles');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(9);
    expect($response->json('meta.last_page'))->toBe(2);
});

it('filters by category', function (): void {
    Article::factory()->create(['category' => 'evenement', 'slug' => 'a1', 'status' => 'published', 'published_at' => now()->subDay()]);
    Article::factory()->create(['category' => 'cooperation', 'slug' => 'a2', 'status' => 'published', 'published_at' => now()->subDay()]);
    Article::factory()->create(['category' => 'evenement', 'slug' => 'a3', 'status' => 'published', 'published_at' => now()->subDay()]);

    $response = $this->getJson('/v1/articles?category=evenement');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('filters by featured', function (): void {
    Article::factory()->pinned()->create(['slug' => 'p1', 'status' => 'published', 'published_at' => now()->subDay()]);
    Article::factory()->create(['slug' => 'p2', 'status' => 'published', 'published_at' => now()->subDay()]);

    $response = $this->getJson('/v1/articles?featured=true');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('orders by published_at desc by default', function (): void {
    Article::factory()->create(['slug' => 'old', 'status' => 'published', 'published_at' => now()->subMonth()]);
    Article::factory()->create(['slug' => 'recent', 'status' => 'published', 'published_at' => now()->subDay()]);

    $response = $this->getJson('/v1/articles');

    $response->assertOk();
    $first = $response->json('data.0.slug');
    expect($first)->toBe('recent');
});

it('GET /v1/articles/{slug} returns 404 when slug not found', function (): void {
    $this->getJson('/v1/articles/inexistant')->assertStatus(404);
});

it('GET /v1/articles/{slug} returns 404 for draft articles', function (): void {
    Article::factory()->draft()->create(['slug' => 'draft-only']);

    $this->getJson('/v1/articles/draft-only')->assertStatus(404);
});

it('GET /v1/articles/{slug} returns the body field for published articles', function (): void {
    Article::factory()->create([
        'slug' => 'visible-article',
        'status' => 'published',
        'published_at' => now()->subDay(),
        'title' => ['fr' => 'Mon article'],
        'body' => ['fr' => "## Section\n\nContenu."],
    ]);

    $response = $this->getJson('/v1/articles/visible-article');

    $response->assertOk();
    $response->assertJsonPath('data.slug', 'visible-article');
    $response->assertJsonPath('data.title', 'Mon article');
    $response->assertJsonPath('data.body', "## Section\n\nContenu.");
});

it('list endpoint omits body to keep response payload small', function (): void {
    Article::factory()->create([
        'slug' => 'a-list',
        'status' => 'published',
        'published_at' => now()->subDay(),
        'body' => ['fr' => 'Long body content not exposed in list.'],
    ]);

    $response = $this->getJson('/v1/articles');

    $response->assertOk();
    expect($response->json('data.0.body'))->toBeNull();
});

it('ArticlesSeeder seeds 6 published articles, 2 pinned', function (): void {
    $this->seed(ArticlesSeeder::class);

    expect(Article::query()->where('status', 'published')->count())->toBe(6);
    expect(Article::query()->where('is_pinned', true)->count())->toBe(2);
});

it('ArticlesSeeder is idempotent', function (): void {
    $this->seed(ArticlesSeeder::class);
    $this->seed(ArticlesSeeder::class);

    expect(Article::query()->count())->toBe(6);
});

it('rejects invalid status via CHECK constraint', function (): void {
    expect(fn () => Article::factory()->create(['status' => 'bogus']))
        ->toThrow(QueryException::class);
});
