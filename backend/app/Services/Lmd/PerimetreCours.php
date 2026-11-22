<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\AffectationPointFocal;
use App\Models\AttributionEcue;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cours suivis par un compte au périmètre restreint (spec lot 16b, § 2.4) : les ECUE attribués à
 * un enseignant, et les ECUE des maquettes validées des classes d'un point focal. Règle unique des écrans
 * et des services (saisie du CC et de l'assiduité, séances). Sans droit, le périmètre est vide.
 */
final class PerimetreCours
{
    /** @return Builder<MaquetteEcue> */
    public static function ecues(User $user): Builder
    {
        $attribues = $user->can(PermissionsLmd::NOTES_ENSEIGNANT)
            ? AttributionEcue::query()->where('user_id', $user->id)->pluck('maquette_ecue_id')->all()
            : [];
        $classes = self::classes($user);

        return MaquetteEcue::query()->where(function (Builder $q) use ($attribues, $classes): void {
            $q->whereIn($q->qualifyColumn('id'), $attribues);
            if ($classes !== []) {
                $q->orWhereHas('maquetteUe.maquette', fn (Builder $m) => self::deSesClasses($m->where('statut', Maquette::VALIDEE), $classes));
            }
        });
    }

    public static function couvre(User $user, int $ecueId): bool
    {
        return self::ecues($user)->whereKey($ecueId)->exists();
    }

    /** Inscriptions annuelles des classes d'un point focal ; vide pour tout autre compte. @return Builder<InscriptionAnnuelle> */
    public static function inscriptions(User $user): Builder
    {
        $classes = self::classes($user);

        return InscriptionAnnuelle::query()->where(fn (Builder $q) => $classes === []
            ? $q->whereIn($q->qualifyColumn('id'), [])
            : self::deSesClasses($q, $classes));
    }

    /** @return list<array{parcours_id: int, annee_academique: string}> */
    private static function classes(User $user): array
    {
        if (! $user->can(PermissionsLmd::POINT_FOCAL)) {
            return [];
        }

        return AffectationPointFocal::query()->where('user_id', $user->id)->get(['parcours_id', 'annee_academique'])
            ->map(fn (AffectationPointFocal $a): array => ['parcours_id' => (int) $a->parcours_id, 'annee_academique' => (string) $a->annee_academique])
            ->all();
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query  maquettes ou inscriptions annuelles (mêmes colonnes)
     * @param  non-empty-list<array{parcours_id: int, annee_academique: string}>  $classes
     */
    private static function deSesClasses(Builder $query, array $classes): Builder
    {
        return $query->where(function (Builder $q) use ($classes): void {
            foreach ($classes as $classe) {
                $q->orWhere(fn (Builder $c) => $c->where($c->qualifyColumn('parcours_id'), $classe['parcours_id'])
                    ->where($c->qualifyColumn('annee_academique'), $classe['annee_academique']));
            }
        });
    }
}
