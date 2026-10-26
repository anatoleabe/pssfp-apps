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
use RuntimeException;

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

    /**
     * Année des matricules déjà attribués dans la campagne : une promotion ne
     * mélange pas deux années. Les dossiers supprimés comptent, leur matricule
     * reste réservé.
     */
    public static function anneeFigee(CampagneCandidature $campagne): ?string
    {
        $matricule = Candidature::withTrashed()->forCampagne($campagne->id)
            ->whereNotNull('matricule')->orderBy('rang_retenu')->value('matricule');

        return $matricule === null ? null : substr((string) $matricule, 0, 2);
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
        $figee = self::anneeFigee($campagne);
        if ($figee !== null && $figee !== $annee) {
            throw new InvalidArgumentException("Les matricules de cette promotion commencent déjà par « {$figee} » : l'année ne peut plus changer.");
        }

        $resultat = $this->db->transaction(function () use ($campagne, $annee): array {
            // Verrou sur la campagne : deux clics simultanés ne doivent pas
            // distribuer deux fois le même rang.
            CampagneCandidature::query()->whereKey($campagne->id)->lockForUpdate()->first();

            $deja = self::retenus($campagne)->whereNotNull('matricule')->count();
            // withTrashed : un rang porté par un dossier supprimé reste pris
            // (les index uniques le couvrent aussi).
            $rang = (int) Candidature::withTrashed()->forCampagne($campagne->id)->max('rang_retenu');

            $nouveaux = $this->parOrdreAlphabetique(self::retenus($campagne)->whereNull('matricule')->get());
            foreach ($nouveaux as $candidature) {
                $rang++;
                $matricule = $annee.$candidature->categorie_matricule.str_pad((string) $rang, 3, '0', STR_PAD_LEFT);
                // Unicité sur toute la table : une autre campagne de la même
                // année aurait déjà pu le distribuer.
                if (Candidature::withTrashed()->where('matricule', $matricule)->exists()) {
                    throw new RuntimeException("Le matricule {$matricule} existe déjà dans une autre campagne : vérifiez l'année saisie.");
                }
                $candidature->forceFill([
                    'rang_retenu' => $rang,
                    'matricule' => $matricule,
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
     * Mot de passe Moodle initial de 10 caractères : au moins une majuscule,
     * une minuscule, un chiffre et « @ » (politique par défaut de Moodle), le
     * reste tiré au hasard, puis l'ordre mélangé. Sans caractères ambigus
     * (I, l, O, 0, 1), puisqu'il est recopié à la main par le candidat.
     */
    public static function motDePasse(): string
    {
        $majuscules = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $minuscules = 'abcdefghijkmnpqrstuvwxyz';
        $chiffres = '23456789';
        $tous = $majuscules.$minuscules.$chiffres;
        $tirer = fn (string $alphabet): string => $alphabet[random_int(0, strlen($alphabet) - 1)];

        $caracteres = [$tirer($majuscules), $tirer($minuscules), $tirer($chiffres), '@'];
        while (count($caracteres) < 10) {
            $caracteres[] = $tirer($tous);
        }
        // Mélange de Fisher-Yates sur random_int : shuffle() n'est pas cryptographique.
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
        }

        return implode('', $caracteres);
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
