<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Auditeur;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Maquette;
use Illuminate\Database\Eloquent\Builder;

/**
 * Numérotation des semestres à l'affichage, réglée par promotion (réglages de
 * la promotion) : « master », S1 à S4, ou « lmd », S7 à S10 comme l'université.
 *
 * Seul endroit où se compose le libellé d'un semestre. Le semestre enregistré
 * (maquettes, notes, calculs, codes d'UE) ne change jamais : les maquettes de
 * 2026-2027 l'enregistrent de 7 à 10, une maquette saisie de 1 à 4 s'affiche
 * de la même façon. Les documents déjà délivrés sont des fichiers scellés :
 * ils gardent la numérotation de leur délivrance.
 */
final class NumerotationSemestres
{
    public const MASTER = 'master';

    public const LMD = 'lmd';

    public const MODES = [
        self::MASTER => 'Master (S1 à S4)',
        self::LMD => 'LMD (S7 à S10)',
    ];

    /** Le semestre 1 du master est le semestre 7 du LMD. */
    public const DECALAGE_LMD = 6;

    public static function mode(?CampagneCandidature $promotion): string
    {
        $mode = $promotion?->numerotation_semestres;

        return is_string($mode) && array_key_exists($mode, self::MODES) ? $mode : self::MASTER;
    }

    /** Rang du semestre dans le master (1 à 4), que la maquette l'enregistre de 7 à 10 ou de 1 à 4. */
    public static function rang(int $semestre): int
    {
        return $semestre > self::DECALAGE_LMD ? $semestre - self::DECALAGE_LMD : $semestre;
    }

    /** Niveau d'un semestre enregistré : les deux premiers en M1, les suivants en M2. */
    public static function niveau(int $semestre): string
    {
        return self::rang($semestre) <= 2 ? 'M1' : 'M2';
    }

    /** Numéro affiché d'un semestre enregistré, pour cette promotion. */
    public static function numero(int $semestre, ?CampagneCandidature $promotion): int
    {
        $rang = self::rang($semestre);

        return self::mode($promotion) === self::LMD ? $rang + self::DECALAGE_LMD : $rang;
    }

    /** « Semestre 1 », ou « Semestre 7 » en LMD. */
    public static function libelle(int $semestre, ?CampagneCandidature $promotion): string
    {
        return 'Semestre '.self::numero($semestre, $promotion);
    }

    /** « S1 », ou « S7 » en LMD. */
    public static function libelleCourt(int $semestre, ?CampagneCandidature $promotion): string
    {
        return 'S'.self::numero($semestre, $promotion);
    }

    /**
     * Libellés de semestres enregistrés, pour une année : chacun dans la numérotation
     * de la promotion qui suit son niveau cette année-là (défaut sans année).
     *
     * @param  list<int>  $semestres
     * @return array<int, string>
     */
    public static function options(?string $annee, array $semestres): array
    {
        $promotions = [];
        $options = [];
        foreach ($semestres as $semestre) {
            $niveau = self::niveau($semestre);
            if (! array_key_exists($niveau, $promotions)) {
                $promotions[$niveau] = $annee === null ? null : self::promotion($annee, $niveau);
            }
            $options[$semestre] = self::libelle($semestre, $promotions[$niveau]);
        }

        return $options;
    }

    /**
     * Promotion qui suit ce niveau l'année donnée : en M1, celle dont c'est la
     * première année ; en M2, celle de l'année précédente. Seule une campagne qui
     * a des retenus est une promotion (comme dans Vie académique > Promotions).
     */
    public static function promotion(string $annee, string $niveau): ?CampagneCandidature
    {
        if (preg_match('/^(\d{4})-(\d{4})$/', $annee, $m) !== 1) {
            return null;
        }
        $debut = (int) $m[1] - ($niveau === 'M2' ? 1 : 0);
        $anneeM1 = $debut.'-'.($debut + 1);

        return CampagneCandidature::query()
            ->whereNotNull('promotion_numero')
            ->whereHas('candidatures', fn (Builder $q) => $q->where('statut', Candidature::STATUT_ACCEPTE))
            ->orderByDesc('promotion_numero')->orderBy('id')
            ->get()
            ->first(fn (CampagneCandidature $c): bool => $c->anneeAcademique() === $anneeM1);
    }

    /** Promotion d'une maquette : celle qui suit son parcours l'année de la maquette. */
    public static function promotionDeMaquette(Maquette $maquette): ?CampagneCandidature
    {
        $niveau = $maquette->parcours?->niveau;

        return $niveau === null ? null : self::promotion($maquette->annee_academique, $niveau);
    }

    /** Promotion d'un auditeur : la campagne qui l'a retenu. */
    public static function promotionDeAuditeur(Auditeur $auditeur): ?CampagneCandidature
    {
        return $auditeur->loadMissing('candidature.campagne')->candidature?->campagne;
    }
}
