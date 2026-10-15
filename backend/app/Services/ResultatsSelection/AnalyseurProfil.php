<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Models\Candidature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Calcule les statistiques complémentaires du PV à partir des dossiers
 * retenus (après dédoublonnage éventuel).
 */
final class AnalyseurProfil
{
    private const GENRES = ['M' => 'HOMMES', 'F' => 'FEMMES'];

    /** [âge maximal exclu, libellé], dans l'ordre. */
    private const TRANCHES = [
        [25, 'Moins de 25 ans'],
        [30, '25 à 29 ans'],
        [35, '30 à 34 ans'],
        [40, '35 à 39 ans'],
        [45, '40 à 44 ans'],
        [PHP_INT_MAX, '45 ans et plus'],
    ];

    private const NON_PRECISE = 'Non précisé';

    /**
     * @param  Collection<int, Candidature>  $retenus
     * @param  CarbonImmutable  $reference  date à laquelle les âges sont calculés
     */
    public function analyser(Collection $retenus, CarbonImmutable $reference): ProfilRetenus
    {
        $ages = $retenus
            ->filter(fn (Candidature $c): bool => $c->date_naissance !== null)
            ->map(fn (Candidature $c): int => (int) $c->date_naissance->diffInYears($reference, true));

        $publics = (array) config('resultats_selection.statuts_secteur_public', []);

        return new ProfilRetenus(
            parStatut: $this->parStatut($retenus),
            parAge: $this->parAge($retenus, $reference),
            parDiplome: SpecialitesOfficielles::repartir(
                $retenus,
                fn (Candidature $c): ?string => $c->diplome_requis,
                $this->majuscules((array) config('diplome_requis', [])),
            ),
            parDomaine: SpecialitesOfficielles::repartir(
                $retenus,
                fn (Candidature $c): ?string => $c->domaine_diplome_requis,
                $this->majuscules((array) config('domaines_diplome', [])),
            ),
            ageMoyen: $ages->isEmpty() ? null : round((float) $ages->avg(), 1),
            ageMinimum: $ages->min(),
            ageMaximum: $ages->max(),
            total: $retenus->count(),
            femmes: $retenus->where('genre', 'F')->count(),
            distanciel: $retenus->where('type_etude', 'distanciel')->count(),
            fonctionnaires: $retenus->where('statut_actuel', 'Fonctionnaire')->count(),
            secteurPublic: $retenus->filter(fn (Candidature $c): bool => in_array($c->statut_actuel, $publics, true))->count(),
            anglophones: $retenus->where('premiere_langue', 'en')->count(),
        );
    }

    /**
     * Une ligne par statut actuel proposé aujourd'hui, dans l'ordre du
     * formulaire ; l'ancien statut « Fonctionnaire / Contractuel » et « non
     * précisé » n'apparaissent que s'ils concernent un dossier.
     *
     * @param  Collection<int, Candidature>  $retenus
     */
    private function parStatut(Collection $retenus): Repartition
    {
        $libelles = (array) config('statuts_actuels', []);
        $lignes = array_values(array_diff_key($libelles, ['Fonctionnaire-Contractuel' => true]));

        $colonnes = [];
        foreach (SpecialitesOfficielles::ordonnees() as $libelle) {
            $colonnes[$libelle] = SpecialitesOfficielles::sigle($libelle);
        }

        return Repartition::croiser(
            $retenus,
            $lignes,
            fn (Candidature $c): string => $libelles[(string) $c->statut_actuel] ?? self::NON_PRECISE,
            $colonnes,
            fn (Candidature $c): string => SpecialitesOfficielles::libelle($c->specialite),
        );
    }

    /** @param  Collection<int, Candidature>  $retenus */
    private function parAge(Collection $retenus, CarbonImmutable $reference): Repartition
    {
        return Repartition::croiser(
            $retenus,
            array_column(self::TRANCHES, 1),
            fn (Candidature $c): string => $c->date_naissance === null
                ? self::NON_PRECISE
                : $this->tranche((int) $c->date_naissance->diffInYears($reference, true)),
            self::GENRES,
            fn (Candidature $c): ?string => $c->genre,
            ['autre' => 'AUTRE'],
        );
    }

    private function tranche(int $age): string
    {
        foreach (self::TRANCHES as [$borne, $libelle]) {
            if ($age < $borne) {
                return $libelle;
            }
        }

        return self::NON_PRECISE;
    }

    /**
     * @param  array<string, string>  $libelles
     * @return array<string, string>
     */
    private function majuscules(array $libelles): array
    {
        return array_map(fn (string $l): string => mb_strtoupper($l), $libelles);
    }
}
