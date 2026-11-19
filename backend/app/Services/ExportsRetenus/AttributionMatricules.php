<?php

declare(strict_types=1);

namespace App\Services\ExportsRetenus;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\ReinscriptionAcma;
use App\Models\User;
use App\Services\Acma\ArchivesAcma;
use App\Services\Acma\DoublonsAncien;
use App\Services\Scolarite\AnneeEnCours;
use App\Services\Scolarite\OuvertureAuditeur;
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
    public const LONGUEUR_MOT_DE_PASSE = 6;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly OuvertureAuditeur $ouverture,
    ) {}

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
        // Seuls les matricules attribués ici : un matricule repris d'ACMA (sans rang, ou à la place d'un matricule
        // dont le rang reste pris, 15m) ne fige pas l'année.
        $matricule = Candidature::withTrashed()->forCampagne($campagne->id)
            ->whereNotNull('matricule')->whereNotNull('rang_retenu')
            ->whereNotIn('id', ReinscriptionAcma::query()->select('candidature_id'))
            ->orderBy('rang_retenu')->value('matricule');
        if ($matricule === null) {
            // Promotion dont le seul matricule attribué a été repris : l'année vient de l'ancien matricule.
            $matricule = ReinscriptionAcma::query()->where('campagne_id', $campagne->id)->whereNotNull('ancien_matricule')->orderBy('id')->value('ancien_matricule');
        }

        return $matricule === null ? null : substr((string) $matricule, 0, 2);
    }

    /** « 26 » pour l'année académique 2026-2027. */
    public static function anneeParDefaut(CampagneCandidature $campagne): string
    {
        return substr($campagne->anneeAcademique(), 2, 2);
    }

    /**
     * @param  list<int>|null  $seulement  ids des dossiers à numéroter (sélection
     *                                     faite dans l'admin) ; null = tous les retenus
     * @return array{attribues: int, deja: int, ids: list<int>}
     */
    public function attribuer(CampagneCandidature $campagne, string $annee, User $auteur, ?array $seulement = null): array
    {
        if (preg_match('/^\d{2}$/', $annee) !== 1) {
            throw new InvalidArgumentException('L\'année du matricule doit compter deux chiffres.');
        }
        // Aucune attribution hors de la promotion en cours (décision du 16/11/2026).
        AnneeEnCours::exigerPromotionCourante($campagne);
        $figee = self::anneeFigee($campagne);
        if ($figee !== null && $figee !== $annee) {
            throw new InvalidArgumentException("Les matricules de cette promotion commencent déjà par « {$figee} » : l'année ne peut plus changer.");
        }

        $resultat = $this->db->transaction(function () use ($campagne, $annee, $seulement, $auteur): array {
            // Verrou sur la campagne : deux clics simultanés ne doivent pas
            // distribuer deux fois le même rang.
            CampagneCandidature::query()->whereKey($campagne->id)->lockForUpdate()->first();

            $deja = self::retenus($campagne)->whereNotNull('matricule')->count();
            // withTrashed : un rang porté par un dossier supprimé reste pris
            // (les index uniques le couvrent aussi).
            $rang = (int) Candidature::withTrashed()->forCampagne($campagne->id)->max('rang_retenu');

            $aNumeroter = self::retenus($campagne)->whereNull('matricule');
            if ($seulement !== null) {
                // Sélection : rang suivant le dernier attribué, ordre alphabétique
                // au sein de la sélection. Les non-retenus sont ignorés.
                $aNumeroter->whereIn('id', $seulement);
            }
            $nouveaux = $this->parOrdreAlphabetique($aNumeroter->get());
            foreach ($nouveaux as $candidature) {
                // Un ancien déjà réinscrit depuis l'archive garde son matricule ACMA : pas de second dossier (16/11/2026).
                $reinscrit = DoublonsAncien::reinscritPour($candidature);
                if ($reinscrit !== null && ! $auteur->can(ArchivesAcma::CONSULTER)) {
                    throw new RuntimeException("Le retenu {$candidature->numero_dossier} est peut-être déjà auditeur : la scolarité doit vérifier ce dossier avant l'attribution.");
                }
                if ($reinscrit !== null) {
                    throw new RuntimeException("Le retenu {$candidature->numero_dossier} est déjà auditeur depuis l'archive ACMA (dossier {$reinscrit->numero_dossier}, matricule {$reinscrit->matricule}) : retirez sa candidature, puis attribuez les matricules.");
                }
                // Probable ancien auditeur : le dossier ACMA fait foi (Q2), son matricule se reprend depuis l'archive.
                $archive = DoublonsAncien::dossierArchivePour($candidature);
                if ($archive !== null && ! $auteur->can(ArchivesAcma::CONSULTER)) {
                    throw new RuntimeException("Le retenu {$candidature->numero_dossier} est peut-être un ancien auditeur : la scolarité doit vérifier ce dossier avant l'attribution.");
                }
                if ($archive !== null) {
                    throw new RuntimeException("Le retenu {$candidature->numero_dossier} semble être l'ancien auditeur {$archive->matricule} : reprenez son matricule ACMA depuis sa fiche d'archive (ou arbitrez « personnes différentes »), puis attribuez les autres.");
                }
                $rang++;
                $matricule = $annee.$candidature->categorie_matricule.str_pad((string) $rang, 3, '0', STR_PAD_LEFT);
                // Unicité sur toute la table : une autre campagne de la même
                // année aurait déjà pu le distribuer.
                if (Candidature::withTrashed()->where('matricule', $matricule)->exists() || ReinscriptionAcma::query()->where('ancien_matricule', $matricule)->exists()) {
                    throw new RuntimeException("Le matricule {$matricule} existe déjà dans une autre campagne : vérifiez l'année saisie.");
                }
                $candidature->forceFill([
                    'rang_retenu' => $rang,
                    'matricule' => $matricule,
                    'moodle_password' => self::motDePasse(),
                    'matricule_attribue_at' => now(),
                ])->save();
                $this->ouverture->ouvrir($candidature);
            }

            return ['attribues' => $nouveaux->count(), 'deja' => $deja, 'ids' => $nouveaux->map(fn (Candidature $c): int => (int) $c->id)->all()];
        });

        activity('candidatures')->causedBy($auteur)->performedOn($campagne)
            ->event('matricules_attribues')
            ->withProperties(['attribues' => $resultat['attribues'], 'deja' => $resultat['deja'], 'annee' => $annee, 'selection' => $seulement !== null])
            ->log('Matricules des retenus attribués');

        return $resultat;
    }

    /**
     * Mot de passe Moodle de 6 caractères, tout en minuscules (lettres et chiffres, sans caractère spécial) :
     * décision du chef de projet du 19/11/2026 pour les nouveaux comptes et les réinitialisations. eCampus
     * n'impose aucune règle de complexité. Au moins une lettre et un chiffre ; sans caractères ambigus
     * (l, o, 0, 1), puisqu'il est recopié à la main. Les mots de passe déjà remis ne changent pas.
     */
    public static function motDePasse(): string
    {
        $lettres = 'abcdefghijkmnpqrstuvwxyz';
        $chiffres = '23456789';
        $tirer = fn (string $alphabet): string => $alphabet[random_int(0, strlen($alphabet) - 1)];

        $caracteres = [$tirer($lettres), $tirer($chiffres)];
        while (count($caracteres) < self::LONGUEUR_MOT_DE_PASSE) {
            $caracteres[] = $tirer($lettres.$chiffres);
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
