<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Models\Candidature;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Repère une même personne retenue au titre de plusieurs dossiers (comptes
 * différents, téléphones différents, parfois spécialités différentes).
 *
 * Identité : nom et prénoms — sans accents, casse ni ordre des mots, pour
 * couvrir les inversions nom / prénom — plus la date de naissance.
 *
 * Dossier gardé (arbitrage du 13 oct. 2026) : celui dont le papier a été
 * réceptionné en dernier, c'est celui que le jury a en main ; à égalité, le
 * plus récemment soumis.
 */
final class DetecteurDoublons
{
    /**
     * @param  Collection<int, Candidature>  $retenus
     * @return list<Doublon>
     */
    public function doublons(Collection $retenus): array
    {
        return $this->groupes($retenus)
            ->map(function (Collection $groupe): Doublon {
                $conserve = $this->aConserver($groupe);

                return new Doublon(
                    nomComplet: mb_strtoupper(trim($conserve->nom.' '.$conserve->prenom)),
                    numeros: $groupe->pluck('numero_dossier')->map(fn ($n): string => (string) $n)->sort()->values()->all(),
                    conserve: (string) $conserve->numero_dossier,
                );
            })
            ->sortBy(fn (Doublon $d): string => Str::ascii($d->nomComplet))
            ->values()
            ->all();
    }

    /**
     * Un seul dossier par personne.
     *
     * @param  Collection<int, Candidature>  $retenus
     * @return Collection<int, Candidature>
     */
    public function dedoublonner(Collection $retenus): Collection
    {
        $ecartes = $this->groupes($retenus)
            ->flatMap(fn (Collection $groupe): array => $groupe
                ->reject(fn (Candidature $c): bool => $c->is($this->aConserver($groupe)))
                ->pluck('id')
                ->all());

        return $retenus->reject(fn (Candidature $c): bool => $ecartes->contains($c->id))->values();
    }

    /**
     * @param  Collection<int, Candidature>  $retenus
     * @return Collection<string, Collection<int, Candidature>>
     */
    private function groupes(Collection $retenus): Collection
    {
        return $retenus
            ->groupBy(fn (Candidature $c): string => $this->identite($c))
            ->filter(fn (Collection $groupe): bool => $groupe->count() > 1);
    }

    private function identite(Candidature $c): string
    {
        // Sans date de naissance, impossible de distinguer deux homonymes :
        // le dossier reste seul plutôt que d'être écarté à tort.
        if ($c->date_naissance === null) {
            return '#'.$c->id;
        }

        // « Jean-Paul » = « Jean Paul », « NGO'O » = « NGO O ».
        $texte = (string) preg_replace("/[-'’]/u", ' ', $c->nom.' '.$c->prenom);
        $mots = preg_split('/\s+/', Str::upper(Str::ascii($texte)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($mots);

        return implode(' ', $mots).'|'.$c->date_naissance->toDateString();
    }

    /** @param  Collection<int, Candidature>  $groupe */
    private function aConserver(Collection $groupe): Candidature
    {
        return $groupe
            ->sortByDesc(fn (Candidature $c): string => sprintf(
                '%s|%s|%012d',
                $c->depot_physique_at?->format('YmdHis') ?? '',
                $c->submitted_at?->format('YmdHis') ?? '',
                $c->id,
            ))
            ->first();
    }
}
