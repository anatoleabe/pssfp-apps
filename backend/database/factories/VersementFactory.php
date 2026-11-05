<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Versement> */
class VersementFactory extends Factory
{
    protected $model = Versement::class;

    public function definition(): array
    {
        return [
            'inscription_annuelle_id' => InscriptionAnnuelle::factory(),
            'montant' => 185000,
            'date_versement' => now()->toDateString(),
            'mode' => 'cremincam_agence',
            'reference' => 'BOR-'.$this->faker->unique()->numerify('########'),
            'payeur' => 'auditeur',
        ];
    }
}
