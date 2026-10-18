<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MotCle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MotCle> */
class MotCleFactory extends Factory
{
    protected $model = MotCle::class;

    public function definition(): array
    {
        $label = $this->faker->unique()->words(2, true);

        return ['slug' => Str::slug($label), 'label' => ['fr' => $label]];
    }
}
