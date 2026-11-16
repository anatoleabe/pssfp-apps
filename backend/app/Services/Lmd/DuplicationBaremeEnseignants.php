<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\BaremeRemuneration;
use App\Models\User;
use App\Support\Journaux;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Duplique toutes les lignes du barème des enseignants d'une année vers une année sans barème. Les copies arrivent
 * « proposées » au nom de l'auteur, sans référence de texte : un texte de l'année cible doit fonder chaque montant
 * avant sa validation par un autre responsable (double regard).
 */
final class DuplicationBaremeEnseignants
{
    /** @return array<string, string> années qui ont un barème, la plus récente en premier */
    public function anneesSources(): array
    {
        $annees = BaremeRemuneration::query()->distinct()->orderByDesc('annee_academique')->pluck('annee_academique')->all();

        return array_combine($annees, $annees);
    }

    public static function anneeSuivante(string $annee): ?string
    {
        if (preg_match('/^(\d{4})-(\d{4})$/', $annee, $m) !== 1) {
            return null;
        }

        return ((int) $m[1] + 1).'-'.((int) $m[2] + 1);
    }

    /** @return int nombre de lignes créées */
    public function dupliquer(string $source, string $cible, User $auteur): int
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_PREPARER), 403);
        if (preg_match('/^(\d{4})-(\d{4})$/', $cible, $m) !== 1 || (int) $m[2] !== (int) $m[1] + 1) {
            throw new InvalidArgumentException('L\'année à créer s\'écrit comme 2027-2028 : deux années qui se suivent.');
        }
        if ((int) $m[1] <= (int) substr($source, 0, 4)) {
            throw new InvalidArgumentException('L\'année à créer doit suivre l\'année copiée.');
        }

        try {
            return $this->copier($source, $cible, $auteur);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException("L'année {$cible} a déjà un barème : modifiez ses lignes une à une.");
        }
    }

    private function copier(string $source, string $cible, User $auteur): int
    {
        return DB::transaction(function () use ($source, $cible, $auteur): int {
            if (BaremeRemuneration::query()->where('annee_academique', $cible)->lockForUpdate()->exists()) {
                throw new InvalidArgumentException("L'année {$cible} a déjà un barème : modifiez ses lignes une à une.");
            }
            $lignes = BaremeRemuneration::query()->where('annee_academique', $source)->orderBy('id')->get();
            if ($lignes->isEmpty()) {
                throw new InvalidArgumentException("L'année {$source} n'a pas de barème à copier.");
            }
            foreach ($lignes as $l) {
                (new BaremeRemuneration)->forceFill([
                    'annee_academique' => $cible, 'nature' => $l->nature, 'unite' => $l->unite,
                    'montant_unitaire' => $l->montant_unitaire, 'taux_retenue' => $l->taux_retenue, 'reference_texte' => null,
                    'statut' => 'propose', 'modifie_par' => $auteur->id, 'valide_par' => null, 'valide_at' => null,
                ])->save();
            }
            activity(Journaux::PAIE)->causedBy($auteur)->event('bareme_duplique')
                ->withProperties(['source' => $source, 'cible' => $cible, 'lignes' => $lignes->count()])
                ->log("Barème des enseignants {$source} dupliqué vers {$cible}");

            return $lignes->count();
        });
    }
}
