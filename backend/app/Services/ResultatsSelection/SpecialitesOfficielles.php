<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use Illuminate\Support\Str;

/**
 * Rattache chaque dossier à l'une des cinq spécialités officielles : le PV et
 * le communiqué n'ont pas de ligne « autre spécialité ».
 *
 * Certains dossiers portent un intitulé ancien (« Audit - Contrôle de Gestion
 * Publique ») : on le reconnaît à un mot-clé ; à défaut, spécialité par défaut
 * (arbitrage du 13 oct. 2026 : Marchés publics et PPP).
 */
final class SpecialitesOfficielles
{
    /** @return array<string, string> clé => libellé, dans l'ordre des PV */
    public static function ordonnees(): array
    {
        $libelles = (array) config('specialites', []);
        $ordre = array_intersect_key(array_flip((array) config('resultats_selection.ordre_specialites', [])), $libelles);

        $resultat = [];
        foreach (array_keys($ordre + $libelles) as $cle) {
            $resultat[$cle] = $libelles[$cle];
        }

        return $resultat;
    }

    /** Libellé officiel de la spécialité d'un dossier. */
    public static function libelle(?string $specialite): string
    {
        $libelles = (array) config('specialites', []);
        if (in_array($specialite, $libelles, true)) {
            return (string) $specialite;
        }

        $normalise = Str::lower(Str::ascii((string) $specialite));
        foreach ((array) config('resultats_selection.mots_cles_specialites', []) as $motCle => $cle) {
            $motCle = Str::lower(Str::ascii((string) $motCle));
            if ($normalise !== '' && $motCle !== '' && str_contains($normalise, $motCle) && isset($libelles[$cle])) {
                return $libelles[$cle];
            }
        }

        return $libelles[(string) config('resultats_selection.specialite_par_defaut')]
            ?? (string) end($libelles);
    }
}
