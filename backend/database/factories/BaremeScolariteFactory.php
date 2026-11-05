<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BaremeScolarite;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BaremeScolarite> */
class BaremeScolariteFactory extends Factory
{
    protected $model = BaremeScolarite::class;

    public function definition(): array
    {
        $debut = $this->faker->unique()->numberBetween(2100, 9000);

        return [
            'annee_academique' => $debut.'-'.($debut + 1),
            'niveau' => BaremeScolarite::NIVEAU_M1,
            'montant_inscription' => 185000,
            'montant_tranche1' => 500000,
            'montant_tranche2' => 500000,
        ];
    }

    public function m2(): static
    {
        return $this->state(['niveau' => BaremeScolarite::NIVEAU_M2, 'montant_inscription' => 135000]);
    }
}
