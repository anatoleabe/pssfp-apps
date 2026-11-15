<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Etiquette;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Etiquette> */
class EtiquetteFactory extends Factory
{
    protected $model = Etiquette::class;

    public function definition(): array
    {
        return [
            'nom' => 'Étiquette '.$this->faker->unique()->numberBetween(1, 999999),
            'couleur' => 'gray',
        ];
    }
}
