<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(6);

        return [
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numerify('####'),
            'title' => ['fr' => $title],
            'excerpt' => ['fr' => $this->faker->sentence(15)],
            'body' => ['fr' => $this->faker->paragraphs(4, true)],
            'category' => $this->faker->randomElement(array_keys(Article::CATEGORIES)),
            'status' => Article::STATUS_PUBLISHED,
            'published_at' => now()->subDays($this->faker->numberBetween(1, 60)),
            'is_pinned' => false,
            'views_count' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => Article::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => Article::STATUS_DRAFT,
            'published_at' => null,
        ]);
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['is_pinned' => true]);
    }
}
