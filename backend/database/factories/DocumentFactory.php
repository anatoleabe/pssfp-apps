<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(6);

        return [
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numerify('#####'),
            'type' => 'memoire',
            'title' => ['fr' => $title],
            'abstract' => ['fr' => $this->faker->paragraph()],
            'year' => $this->faker->numberBetween(2010, 2026),
            'language' => 'fr',
            'access_level' => 'public',
            'status' => Document::STATUS_DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => Document::STATUS_PUBLISHED, 'published_at' => now()->subDay()]);
    }

    public function reserved(): static
    {
        return $this->state(fn () => ['access_level' => 'auditor']);
    }

    public function paid(int $price = 2000): static
    {
        return $this->state(fn () => ['price_xaf' => $price]);
    }

    public function external(string $url = 'https://exemple.org/ouvrage'): static
    {
        return $this->state(fn () => ['external_url' => $url, 'file_path' => null]);
    }

    public function withFile(): static
    {
        return $this->state(fn (array $attrs) => [
            'file_path' => 'documents/'.Str::uuid().'.pdf',
            'file_size_bytes' => 120_000,
            'file_checksum_md5' => md5((string) $attrs['slug']),
        ]);
    }
}
