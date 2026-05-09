<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(3);

        return [
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numerify('####'),
            'parent_slug' => null,
            'title' => ['fr' => $title],
            'excerpt' => ['fr' => $this->faker->sentence(12)],
            'body' => ['fr' => $this->faker->paragraphs(3, true)],
            'meta_title' => ['fr' => $title],
            'meta_description' => ['fr' => $this->faker->sentence(15)],
            'og_image_path' => null,
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'order' => 0,
            'is_in_menu' => false,
            'menu_label' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => Page::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => Page::STATUS_DRAFT,
            'published_at' => null,
        ]);
    }

    public function inMenu(int $order = 0, ?string $label = null): static
    {
        return $this->state(fn () => [
            'is_in_menu' => true,
            'order' => $order,
            'menu_label' => $label !== null ? ['fr' => $label] : null,
        ]);
    }
}
