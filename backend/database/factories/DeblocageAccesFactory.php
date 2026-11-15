<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DeblocageAcces;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Déblocage d'accès posé directement en base, pour les listes et les contrôles.
 * Les gestes réels passent par App\Services\Scolarite\DeblocagesAcces.
 *
 * @extends Factory<DeblocageAcces>
 */
class DeblocageAccesFactory extends Factory
{
    protected $model = DeblocageAcces::class;

    public function definition(): array
    {
        return [
            'inscription_annuelle_id' => InscriptionAnnuelle::factory(),
            'type' => DeblocageAcces::VIREMENT_ANNONCE,
            'motif' => 'Virement annoncé, preuve fournie au guichet',
            'justificatif_path' => 'scolarite/justificatifs/01JABCDEFGHJKMNPQRSTVWXYZ0.pdf',
            'echeance' => InscriptionAnnuelle::aujourdhui()->addDays(15)->toDateString(),
            'statut' => DeblocageAcces::EN_COURS,
            'accorde_par' => User::factory(),
        ];
    }

    public function moratoire(): static
    {
        return $this->state(['type' => DeblocageAcces::MORATOIRE, 'justificatif_path' => null]);
    }

    /** Échéance dépassée de quelques jours. */
    public function echu(int $jours = 3): static
    {
        return $this->state(['echeance' => InscriptionAnnuelle::aujourdhui()->subDays($jours)->toDateString()]);
    }
}
