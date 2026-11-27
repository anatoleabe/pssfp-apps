<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\Parcours;
use App\Models\User;
use App\Services\ResultatsSelection\SpecialitesOfficielles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Changement de la spécialité d'un auditeur (27/11/2026), réservé à l'administrateur.
 *
 * La spécialité vit à deux endroits : le dossier de candidature (listes, exports, passage en M2)
 * et, en M2, le parcours de l'inscription de l'année (UE, notes, délibérations). Le changement
 * les aligne en une transaction : spécialité du dossier, parcours de l'option correspondante,
 * inscription pédagogique refaite. En M1 (tronc commun) ou sans inscription, seul le dossier change.
 * Refusé si des notes, de l'assiduité ou une dérogation pèsent sur les UE du parcours quitté.
 */
final class ChangementSpecialite
{
    public const PERMISSION = PermissionsLmd::CHANGER_SPECIALITE;

    public const EVENEMENT = 'specialite_changee';

    private const MOTIF_MIN = 10;

    public function __construct(private readonly InscriptionsPedagogiques $inscriptions) {}

    /** @return array<string, string> libellé => libellé, dans l'ordre des PV */
    public static function options(): array
    {
        $libelles = array_values(SpecialitesOfficielles::ordonnees());

        return array_combine($libelles, $libelles);
    }

    /** Option de M2 active de cette spécialité, si l'inscription de l'année est en M2 ; sinon null. */
    public function parcoursCible(Auditeur $auditeur, string $specialite): ?Parcours
    {
        if ($auditeur->inscriptionCourante?->niveau !== BaremeScolarite::NIVEAU_M2) {
            return null;
        }
        $cle = ParcoursAuditeur::normaliser($specialite);

        return Parcours::query()->where('actif', true)->where('niveau', BaremeScolarite::NIVEAU_M2)->whereNotNull('specialite')->get()
            ->first(fn (Parcours $p): bool => ParcoursAuditeur::normaliser((string) $p->specialite) === $cle);
    }

    /**
     * @return array{specialite: string, parcours: ?string, issue: ?string, ues: int}
     *
     * @throws InvalidArgumentException motif trop court, spécialité inconnue ou déjà en place, option introuvable, notes saisies
     */
    public function changer(Auditeur $auditeur, string $specialite, string $motif, User $auteur): array
    {
        abort_unless($auteur->can(self::PERMISSION), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < self::MOTIF_MIN) {
            throw new InvalidArgumentException('Indiquez le motif du changement ('.self::MOTIF_MIN.' caractères au moins).');
        }
        if (! array_key_exists($specialite, self::options())) {
            throw new InvalidArgumentException('Cette spécialité ne fait pas partie des spécialités officielles.');
        }
        $candidature = $auditeur->candidature ?? throw new InvalidArgumentException('Cet auditeur n\'a pas de dossier de candidature.');
        $inscription = $auditeur->inscriptionCourante;
        $cible = $this->parcoursCible($auditeur, $specialite);
        if ($inscription?->niveau === BaremeScolarite::NIVEAU_M2 && $cible === null) {
            throw new InvalidArgumentException('Aucune option de Master 2 active ne correspond à cette spécialité.');
        }

        $changeParcours = $cible !== null && $inscription->parcours_id !== $cible->id;
        $ancienneSpecialite = (string) $candidature->specialite;
        if (! $changeParcours && $ancienneSpecialite === $specialite) {
            throw new InvalidArgumentException('L\'auditeur a déjà cette spécialité.');
        }
        if ($changeParcours && self::aDesTravauxSaisis($inscription)) {
            throw new InvalidArgumentException('Des notes, de l\'assiduité ou une dérogation sont déjà enregistrées sur les UE du parcours actuel : la spécialité ne peut plus changer cette année.');
        }

        $ancienParcours = $inscription?->parcours?->code;

        return DB::transaction(function () use ($auditeur, $candidature, $inscription, $cible, $changeParcours, $specialite, $ancienneSpecialite, $ancienParcours, $motif, $auteur): array {
            $candidature->forceFill(['specialite' => $specialite])->save();
            $resultat = ['specialite' => $specialite, 'parcours' => $inscription?->parcours?->code, 'issue' => null, 'ues' => 0];
            if ($changeParcours) {
                $inscription->inscriptionsPedagogiques()->delete();
                $inscription->forceFill(['parcours_id' => $cible->id])->save();
                $r = $this->inscriptions->inscrire($inscription->refresh(), null);
                $resultat = [...$resultat, 'parcours' => $cible->code, 'issue' => $r['issue'], 'ues' => $r['ues']];
            }
            activity('lmd')->causedBy($auteur)->performedOn($auditeur)->event(self::EVENEMENT)
                ->withProperties([
                    'ancienne_specialite' => $ancienneSpecialite === '' ? null : $ancienneSpecialite,
                    'nouvelle_specialite' => $specialite,
                    'ancien_parcours' => $ancienParcours,
                    'nouveau_parcours' => $resultat['parcours'],
                    'motif' => $motif,
                ])
                ->log('Spécialité changée : '.$specialite.($changeParcours ? ' (parcours '.$cible->code.')' : ''));

            return $resultat;
        });
    }

    /**
     * Auditeurs dont l'inscription de l'année est en M2 sur une option qui n'est pas la spécialité du dossier.
     *
     * @return Collection<int, Auditeur>
     */
    public static function ecarts(): Collection
    {
        return Auditeur::query()
            ->whereHas('inscriptionCourante', fn ($q) => $q->where('niveau', BaremeScolarite::NIVEAU_M2)->whereNotNull('parcours_id'))
            ->with(['inscriptionCourante.parcours', 'candidature:id,matricule,specialite'])
            ->get()
            ->filter(fn (Auditeur $a): bool => filled($a->inscriptionCourante->parcours?->specialite)
                && ParcoursAuditeur::normaliser((string) $a->inscriptionCourante->parcours->specialite) !== ParcoursAuditeur::normaliser((string) $a->candidature?->specialite))
            ->values();
    }

    private static function aDesTravauxSaisis(InscriptionAnnuelle $inscription): bool
    {
        return $inscription->inscriptionsPedagogiques()
            ->where(fn ($q) => $q->has('notes')->orHas('derogationRattrapage')->orHas('pointages'))
            ->exists();
    }
}
