<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InscriptionAnnuelle> */
class InscriptionAnnuelleFactory extends Factory
{
    protected $model = InscriptionAnnuelle::class;

    public function definition(): array
    {
        return [
            'auditeur_id' => Auditeur::factory(),
            'bareme_id' => BaremeScolarite::factory(),
            'annee_academique' => fn (array $a): string => BaremeScolarite::query()->findOrFail($a['bareme_id'])->annee_academique,
            'niveau' => fn (array $a): string => BaremeScolarite::query()->findOrFail($a['bareme_id'])->niveau,
            'montant_inscription' => fn (array $a): int => BaremeScolarite::query()->findOrFail($a['bareme_id'])->montant_inscription,
            'montant_tranche1' => fn (array $a): int => BaremeScolarite::query()->findOrFail($a['bareme_id'])->montant_tranche1,
            'montant_tranche2' => fn (array $a): int => BaremeScolarite::query()->findOrFail($a['bareme_id'])->montant_tranche2,
        ];
    }
}
