<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Candidature>
 */
class CandidatureFactory extends Factory
{
    protected $model = Candidature::class;

    public function definition(): array
    {
        return [
            'campagne_id' => CampagneCandidature::factory(),
            'phone_e164' => '+237'.$this->faker->numerify('6########'),
            'phone_country' => 'CM',
            'email' => $this->faker->unique()->safeEmail(),
            'civilite' => $this->faker->randomElement(['M.', 'Mme']),
            'nom' => $this->faker->lastName(),
            'prenom' => $this->faker->firstName(),
            'epouse' => null,
            'date_naissance' => $this->faker->dateTimeBetween('-50 years', '-18 years')->format('Y-m-d'),
            'lieu_naissance' => $this->faker->city(),
            'genre' => $this->faker->randomElement(['M', 'F']),
            'statut_matrimonial' => 'Célibataire',
            'nationalite' => 'CM',
            'pays_origine' => 'CM',
            'pays_residence' => 'CM',
            'region' => 'CENTRE',
            'departement' => 'Mfoundi',
            'adresse' => $this->faker->streetAddress(),
            'ville_residence' => 'Yaoundé',
            'indicatif1' => '+237',
            'telephone1' => $this->faker->numerify('6########'),
            'indicatif2' => null,
            'telephone2' => null,
            'specialite' => 'Finances Publiques',
            'second_choix' => null,
            'type_etude' => 'presentiel',
            'premiere_langue' => 'fr',
            'diplome_obtenu' => 'Master',
            'institut' => 'Université de Yaoundé II',
            'specialite_diplome' => 'Économie',
            'annee_diplome' => 2024,
            'statut_actuel' => 'Etudiant',
            'employeur' => null,
            'adresse_employeur' => null,
            'tel_employeur' => null,
            'engagement_nom' => $this->faker->name(),
            'moyen_connaissance' => 'Site web',
            'photo_path' => null,
            'statut' => Candidature::STATUT_POSTULANT,
            'frais_paye' => false,
        ];
    }

    public function forCampagne(CampagneCandidature $campagne): static
    {
        return $this->state(fn () => ['campagne_id' => $campagne->id]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'statut' => Candidature::STATUT_CANDIDAT,
            'submitted_at' => now(),
        ]);
    }
}
