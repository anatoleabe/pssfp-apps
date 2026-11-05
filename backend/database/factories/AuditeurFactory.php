<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Auditeur;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Auditeur> */
class AuditeurFactory extends Factory
{
    protected $model = Auditeur::class;

    public function definition(): array
    {
        return [
            'candidature_id' => function (): int {
                $campagne = CampagneCandidature::factory()->create();
                $candidature = Candidature::factory()->forCampagne($campagne)->submitted()->create([
                    'statut' => Candidature::STATUT_ACCEPTE,
                    'phone_e164' => '+2376'.random_int(10000000, 99999999),
                    'email' => Str::random(10).'@example.org',
                ]);
                $candidature->forceFill([
                    'matricule' => '26EFP'.random_int(100000, 999999),
                    'rang_retenu' => 1,
                    'moodle_password' => 'Ab3@efghjk',
                    'matricule_attribue_at' => now(),
                ])->save();

                return (int) $candidature->id;
            },
        ];
    }
}
