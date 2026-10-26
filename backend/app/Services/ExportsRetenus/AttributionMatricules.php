<?php

declare(strict_types=1);

namespace App\Services\ExportsRetenus;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attribue matricule, rang et mot de passe Moodle initial aux retenus d'une
 * campagne qui n'en ont pas encore.
 *
 * Règles reprises de la 13e promotion :
 * - matricule = année sur deux chiffres + catégorie + rang sur trois chiffres
 *   (« 26EFP001 », « 26EFSC002 ») ;
 * - un seul compteur pour EFP et EFSC, dans l'ordre alphabétique nom + prénom ;
 * - une valeur attribuée ne change plus : un retenu ajouté ensuite reçoit le
 *   rang suivant, sans renuméroter les autres.
 */
final class AttributionMatricules
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** Retenus de la campagne, retraits exclus. */
    public static function retenus(CampagneCandidature $campagne): Builder
    {
        return Candidature::query()
            ->forCampagne($campagne->id)
            ->where('statut', Candidature::STATUT_ACCEPTE)
            ->whereNull('withdrawn_at');
    }

    /** « 26 » pour l'année académique 2026-2027. */
    public static function anneeParDefaut(CampagneCandidature $campagne): string
    {
        return substr($campagne->anneeAcademique(), 2, 2);
    }

    /**
     * @return array{attribues: int, deja: int}
     */
    public function attribuer(CampagneCandidature $campagne, string $annee, User $auteur): array
    {
        if (preg_match('/^\d{2}$/', $annee) !== 1) {
            throw new InvalidArgumentException('L\'année du matricule doit compter deux chiffres.');
        }

        $resultat = $this->db->transaction(function () use ($campagne, $annee): array {
            // Verrou sur la campagne : deux clics simultanés ne doivent pas
            // distribuer deux fois le même rang.
            CampagneCandidature::query()->whereKey($campagne->id)->lockForUpdate()->first();

            $deja = self::retenus($campagne)->whereNotNull('matricule')->count();
            $rang = (int) Candidature::query()->forCampagne($campagne->id)->max('rang_retenu');

            $nouveaux = $this->parOrdreAlphabetique(self::retenus($campagne)->whereNull('matricule')->get());
            foreach ($nouveaux as $candidature) {
                $rang++;
                $candidature->forceFill([
                    'rang_retenu' => $rang,
                    'matricule' => $annee.$candidature->categorie_matricule.str_pad((string) $rang, 3, '0', STR_PAD_LEFT),
                    'moodle_password' => self::motDePasse(),
                    'matricule_attribue_at' => now(),
                ])->save();
            }

            return ['attribues' => $nouveaux->count(), 'deja' => $deja];
        });

        activity('candidatures')->causedBy($auteur)->performedOn($campagne)
            ->event('matricules_attribues')
            ->withProperties($resultat + ['annee' => $annee])
            ->log('Matricules des retenus attribués');

        return $resultat;
    }

    /**
     * Mot de passe Moodle initial : majuscule, minuscules, chiffres et « @ »,
     * pour passer la politique par défaut de Moodle. Sans caractères ambigus
     * (I, l, O, 0, 1), puisqu'il est recopié à la main par le candidat.
     */
    public static function motDePasse(): string
    {
        $majuscules = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $minuscules = 'abcdefghijkmnpqrstuvwxyz';
        $chiffres = '23456789';
        $tirer = fn (string $alphabet): string => $alphabet[random_int(0, strlen($alphabet) - 1)];

        return $tirer($majuscules).$tirer($minuscules).$tirer($minuscules)
            .$tirer($chiffres).$tirer($minuscules).$tirer($chiffres).$tirer($majuscules).'@';
    }

    /**
     * @param  Collection<int, Candidature>  $candidatures
     * @return Collection<int, Candidature>
     */
    private function parOrdreAlphabetique(Collection $candidatures): Collection
    {
        return $candidatures->sortBy(fn (Candidature $c): string => Str::upper(Str::ascii(trim((string) $c->nom).' '.trim((string) $c->prenom)))
            .'|'.$c->numero_dossier)->values();
    }
}
