<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
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

    public static function peutChanger(?User $user): bool
    {
        return (bool) $user?->can(RegistreVersements::PERMISSION_BAREME);
    }

    /**
     * Change l'année en cours (spec lot 18, règle 5) : d'un an seulement, vers une année qui a son barème de M1.
     * Journalisé ; aucune inscription n'est touchée.
     *
     * @throws InvalidArgumentException message destiné au responsable ; rien n'est écrit
     */
    public static function changer(string $annee, User $auteur): void
    {
        abort_unless(self::peutChanger($auteur), 403);
        $actuelle = self::annee();
        if (! self::valide($annee)) {
            throw new InvalidArgumentException('Année attendue sous la forme 2027-2028.');
        }
        if ($annee === $actuelle) {
            throw new InvalidArgumentException($annee.' est déjà l\'année en cours.');
        }
        if ($actuelle !== null && abs(self::debut($annee) - self::debut($actuelle)) !== 1) {
            throw new InvalidArgumentException('L\'année en cours ne change que d\'un an à la fois : '.self::suivante($actuelle, 1).' ou '.self::suivante($actuelle, -1).'.');
        }
        if ($actuelle !== null && self::debut($annee) < self::debut($actuelle)
            && InscriptionAnnuelle::query()->where('annee_academique', $actuelle)->exists()) {
            throw new InvalidArgumentException("Des auditeurs sont déjà inscrits en {$actuelle} : le retour à {$annee} n'est plus possible.");
        }
        if (! BaremeScolarite::query()->where('annee_academique', $annee)->where('niveau', BaremeScolarite::NIVEAU_M1)->exists()) {
            throw new InvalidArgumentException("Aucun barème de M1 pour {$annee} : saisissez-le d'abord (Scolarité > Barèmes).");
        }
        AppSettings::set(self::REGLAGE, $annee, (int) $auteur->id);
        activity('scolarite')->causedBy($auteur)->event('annee_en_cours_changee')
            ->withProperties(['avant' => $actuelle, 'apres' => $annee])->log('Année en cours changée');
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
