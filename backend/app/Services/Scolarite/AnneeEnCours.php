<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\CampagneCandidature;
use App\Support\AppSettings;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Année en cours et promotions (spec de la réinscription des anciens, §6.1).
 *
 * - **Année en cours** : réglage `scolarite.annee_en_cours`, sinon `config('scolarite.annee_en_cours')`. Le responsable
 *   la change au moment d'ouvrir l'année suivante (D3) ; ce n'est jamais une date.
 * - **Promotion courante** : la promotion de l'année en cours (numéro le plus grand si plusieurs). Seule elle reçoit
 *   un nouvel auditeur et des matricules attribués par la plateforme.
 * - **Promotion d'accueil** d'une inscription : en M1, la promotion qui entre cette année-là ; en M2, celle entrée
 *   l'année d'avant. Elle porte la cohorte eCampus et la lettre d'accès (15l).
 *
 * Sans année configurée (tests qui ne s'en occupent pas), aucune garde de promotion ne s'applique.
 */
final class AnneeEnCours
{
    public const REGLAGE = 'scolarite.annee_en_cours';

    public static function annee(): ?string
    {
        $annee = AppSettings::get(self::REGLAGE) ?? config('scolarite.annee_en_cours');

        return is_string($annee) && self::valide($annee) ? $annee : null;
    }

    public static function valide(string $annee): bool
    {
        return preg_match('/^(\d{4})-(\d{4})$/', $annee, $m) === 1 && (int) $m[2] === (int) $m[1] + 1;
    }

    /** « 2026-2027 » → 2026 */
    public static function debut(string $annee): int
    {
        return (int) substr($annee, 0, 4);
    }

    public static function suivante(string $annee, int $decalage): string
    {
        $debut = self::debut($annee) + $decalage;

        return $debut.'-'.($debut + 1);
    }

    public static function estCourante(CampagneCandidature $campagne): bool
    {
        $annee = self::annee();

        return $annee === null || $campagne->anneeAcademique() === $annee;
    }

    /**
     * Un nouvel auditeur n'entre que dans la promotion en cours (décision du 16/11/2026) ; un ancien se réinscrit
     * depuis l'archive ACMA.
     *
     * @throws InvalidArgumentException message destiné à l'agent
     */
    public static function exigerPromotionCourante(CampagneCandidature $campagne): void
    {
        if (! self::estCourante($campagne)) {
            throw new InvalidArgumentException('Un nouvel auditeur n\'entre que dans la promotion en cours ('.self::annee().') : un ancien auditeur se réinscrit depuis son dossier d\'archive ACMA.');
        }
    }

    public static function promotionCourante(): ?CampagneCandidature
    {
        $annee = self::annee();

        return $annee === null ? null : self::promotionDeLAnnee($annee);
    }

    /** Promotion entrée en M1 l'année donnée (numéro le plus grand si plusieurs campagnes la portent). */
    public static function promotionDeLAnnee(string $annee): ?CampagneCandidature
    {
        return self::campagnes()->filter(fn (CampagneCandidature $c): bool => $c->anneeAcademique() === $annee)
            ->sortByDesc('promotion_numero')->first();
    }

    public static function promotionAccueil(string $annee, string $niveau): ?CampagneCandidature
    {
        return self::promotionDeLAnnee($niveau === 'M2' ? self::suivante($annee, -1) : $annee);
    }

    /** @return Collection<int, CampagneCandidature> les campagnes sont peu nombreuses (une par promotion) */
    private static function campagnes(): Collection
    {
        return CampagneCandidature::query()->whereNotNull('promotion_numero')->orderBy('id')->get();
    }
}
