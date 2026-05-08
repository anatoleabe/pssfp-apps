<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CampagneCandidature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampagneCandidature>
 */
class CampagneCandidatureFactory extends Factory
{
    protected $model = CampagneCandidature::class;

    public function definition(): array
    {
        $promotion = $this->faker->numberBetween(14, 30);
        $year = $this->faker->numberBetween(26, 99);
        $prefix = "P{$promotion}{$year}-";

        return [
            'slug' => "p{$promotion}-20{$year}-".$this->faker->unique()->lexify('????'),
            'prefix_numero' => $prefix,
            'nom' => "Promotion {$promotion} — Campagne 20{$year}",
            'promotion_numero' => $promotion,
            'opens_at' => now()->subMonth(),
            'closes_at' => now()->addMonth(),
            'results_at' => now()->addMonths(2),
            'status' => 'open',
            'max_voeux' => 1,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'closed',
            'opens_at' => now()->subMonths(6),
            'closes_at' => now()->subMonth(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => 'draft',
            'opens_at' => now()->addMonth(),
            'closes_at' => now()->addMonths(3),
        ]);
    }
}
