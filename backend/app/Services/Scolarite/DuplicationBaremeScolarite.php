<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\BaremeScolarite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Duplique un barème de scolarité vers un autre couple (année, niveau). Proposition : l'autre niveau de la même année
 * s'il n'a pas de barème et que l'année n'est pas passée, sinon la première année suivante libre du même niveau,
 * échéances avancées d'autant (une échéance d'inscription écrite avec une année n'est pas reprise).
 * Les rubriques de frais ne suivent pas : elles se définissent par couple dans l'espace Finances.
 */
final class DuplicationBaremeScolarite
{
    private const CHAMPS = ['annee_academique', 'niveau', 'montant_inscription', 'montant_tranche1', 'montant_tranche2',
        'inscription_echeance', 'tranche1_echeance', 'tranche2_echeance'];

    /** @return array<string, mixed> valeurs proposées au formulaire de duplication */
    public function proposition(BaremeScolarite $source): array
    {
        $valeurs = [
            'montant_inscription' => $source->montant_inscription, 'montant_tranche1' => $source->montant_tranche1,
            'montant_tranche2' => $source->montant_tranche2, 'inscription_echeance' => $source->inscription_echeance,
        ];
        $autreNiveau = $source->niveau === BaremeScolarite::NIVEAU_M1 ? BaremeScolarite::NIVEAU_M2 : BaremeScolarite::NIVEAU_M1;
        $debut = (int) substr($source->annee_academique, 0, 4);
        if ($debut >= self::debutAnneeEnCours() && ! $this->existe($source->annee_academique, $autreNiveau)) {
            return $valeurs + ['annee_academique' => $source->annee_academique, 'niveau' => $autreNiveau,
                'tranche1_echeance' => $source->tranche1_echeance?->toDateString(), 'tranche2_echeance' => $source->tranche2_echeance?->toDateString()];
        }

        $ecart = 1;
        while ($this->existe(($debut + $ecart).'-'.($debut + $ecart + 1), $source->niveau)) {
            $ecart++;
        }
        $avancer = fn (?CarbonImmutable $d): ?string => $d?->addYearsNoOverflow($ecart)->toDateString();

        if (preg_match('/\d{4}/', (string) $source->inscription_echeance) === 1) {
            $valeurs['inscription_echeance'] = null;
        }

        return $valeurs + ['annee_academique' => ($debut + $ecart).'-'.($debut + $ecart + 1), 'niveau' => $source->niveau,
            'tranche1_echeance' => $avancer($source->tranche1_echeance), 'tranche2_echeance' => $avancer($source->tranche2_echeance)];
    }

    /** @param  array<string, mixed>  $donnees  champs du formulaire, déjà validés */
    public function dupliquer(BaremeScolarite $source, array $donnees, User $auteur): BaremeScolarite
    {
        abort_unless($auteur->can('scolarite.bareme'), 403);
        $valeurs = array_intersect_key($donnees, array_flip(self::CHAMPS));
        if ($this->existe((string) ($valeurs['annee_academique'] ?? ''), (string) ($valeurs['niveau'] ?? ''))) {
            throw new InvalidArgumentException('Un barème existe déjà pour cette année et ce niveau : modifiez-le plutôt.');
        }
        try {
            return DB::transaction(function () use ($source, $valeurs, $auteur): BaremeScolarite {
                $copie = BaremeScolarite::query()->create($valeurs);
                activity('scolarite')->causedBy($auteur)->performedOn($copie)->event('bareme_duplique')
                    ->withProperties(['source' => $source->annee_academique.' '.$source->niveau])
                    ->log('Barème dupliqué depuis '.$source->annee_academique.' '.$source->niveau);

                return $copie;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Un barème existe déjà pour cette année et ce niveau : modifiez-le plutôt.');
        }
    }

    /** Première année de l'année académique en cours : elle commence en septembre. */
    private static function debutAnneeEnCours(): int
    {
        $maintenant = now('Africa/Douala');

        return $maintenant->month >= 9 ? $maintenant->year : $maintenant->year - 1;
    }

    private function existe(string $annee, string $niveau): bool
    {
        return BaremeScolarite::query()->where('annee_academique', $annee)->where('niveau', $niveau)->exists();
    }
}
