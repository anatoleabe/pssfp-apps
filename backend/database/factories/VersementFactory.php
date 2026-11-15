<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConfirmationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use App\Services\Scolarite\ImputationsCompte;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * Versement de test, rattaché à l'auditeur de son inscription et imputé comme un vrai
 * (R2, sous le verrou de l'auditeur) : un écrivain d'argent qui oublierait l'imputation
 * laisserait un versement en avance (ADR-0013). `sansImputation()` sert aux tests de la
 * reprise : les versements d'avant D1 n'avaient pas d'imputation.
 *
 * Un versement de test est confirmé par la scolarité, comme l'argent d'avant le 15/11/2026 repris
 * comme confirmé : il compte pour l'accès. `nonConfirme()` le laisse en attente de confirmation.
 *
 * @extends Factory<Versement>
 */
class VersementFactory extends Factory
{
    protected $model = Versement::class;

    /** @var WeakMap<Versement, true>|null versements créés par `sansImputation()` */
    private static ?WeakMap $sansImputation = null;

    /** @var WeakMap<Versement, true>|null versements créés par `nonConfirme()` */
    private static ?WeakMap $nonConfirmes = null;

    public function definition(): array
    {
        return [
            'inscription_annuelle_id' => InscriptionAnnuelle::factory(),
            // Après l'inscription : la clé est déjà résolue quand cette fonction s'évalue.
            'auditeur_id' => fn (array $a): int => (int) InscriptionAnnuelle::query()->whereKey($a['inscription_annuelle_id'])->value('auditeur_id'),
            'montant' => 185000,
            'date_versement' => now()->toDateString(),
            'mode' => 'cremincam_agence',
            'reference' => 'BOR-'.$this->faker->unique()->numerify('########'),
            'payeur' => 'auditeur',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Versement $versement): void {
            if (! isset(self::nonConfirmes()[$versement])) {
                (new ConfirmationVersement)->forceFill([
                    'versement_id' => $versement->id, 'confirme_par' => null, 'confirme_at' => now(), 'reprise' => true,
                ])->save();
            }
            if (isset(self::marques()[$versement]) || $versement->estAnnule()) {
                return;
            }
            app(ImputationsCompte::class)->sousVerrou((int) $versement->auditeur_id, fn (ImputationsCompte $compte) => $compte->apresVersement($versement));
        });
    }

    /** Versement enregistré, en attente de sa confirmation par la scolarité. */
    public function nonConfirme(): static
    {
        return $this->afterMaking(function (Versement $versement): void {
            self::nonConfirmes()[$versement] = true;
        });
    }

    /** @return WeakMap<Versement, true> */
    private static function nonConfirmes(): WeakMap
    {
        return self::$nonConfirmes ??= new WeakMap;
    }

    /** Versement tel qu'avant D1 : aucune imputation (à reprendre par RepriseComptes). */
    public function sansImputation(): static
    {
        return $this->afterMaking(function (Versement $versement): void {
            self::marques()[$versement] = true;
        });
    }

    /** @return WeakMap<Versement, true> */
    private static function marques(): WeakMap
    {
        return self::$sansImputation ??= new WeakMap;
    }
}
