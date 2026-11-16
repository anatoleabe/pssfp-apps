<?php

declare(strict_types=1);

namespace App\Services\Acma;

use App\Models\AcmaDossier;
use App\Models\Auditeur;
use App\Models\Candidature;
use App\Models\ReinscriptionAcma;
use App\Models\User;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\OuvertureAuditeur;
use App\Support\Journaux;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Q2 (tranchée le 16/11/2026 : le dossier ACMA fait foi), spec de la réinscription des anciens, §8.3 : un ancien
 * auditeur revenu par la candidature de la promotion en cours prend son matricule ACMA, sans second dossier.
 *
 * - Mode `conversion` (le dossier a déjà un matricule de la plateforme) : seul le matricule change. Auditeur,
 *   inscription, versements, imputations, confirmations, reçus, déblocages, documents, notes et messages restent tels
 *   quels, puisqu'ils pendent à l'auditeur et à l'inscription. Le rang reste pris (l'ancien matricule n'est jamais
 *   réattribué) ; si le compte eCampus existe, son identifiant est figé (`identifiant_ecampus`) : rien ne change sur
 *   eCampus ni pour la connexion. Les reçus déjà émis gardent l'ancien matricule (instantanés légaux).
 * - Mode `attribution` (retenu encore sans matricule) : le matricule ACMA est attribué à la place d'un nouveau, sans
 *   rang, puis l'auditeur s'ouvre comme à l'attribution ordinaire.
 *
 * Trace d'abord (ajout seul), puis le matricule, puis le lien de l'archive (décision d'abord). Aucun envoi.
 */
final class ConversionMatriculeAcma
{
    public const EVENEMENT = 'matricule_converti_acma';

    public function __construct(private readonly OuvertureAuditeur $ouverture) {}

    /** @throws InvalidArgumentException message destiné à l'agent ; rien n'est écrit */
    public function convertir(AcmaDossier $dossier, Candidature $dossierPlateforme, string $motif, User $auteur, bool $identiteConfirmee = false): Candidature
    {
        abort_unless($auteur->can(ReinscriptionAncien::PERMISSION) && $auteur->can(ArchivesAcma::CONSULTER), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < ReinscriptionAncien::MOTIF_MINIMUM) {
            throw new InvalidArgumentException('Indiquez le motif (au moins '.ReinscriptionAncien::MOTIF_MINIMUM.' caractères).');
        }

        // Même verrou que la création du compte eCampus : jamais une conversion pendant une création de compte.
        $resultat = Cache::lock('ecampus-compte:'.$dossierPlateforme->id, 60)->get(fn (): Candidature => $this->convertirSousVerrou($dossier, $dossierPlateforme, $motif, $auteur, $identiteConfirmee));
        if ($resultat === false) {
            throw new InvalidArgumentException('Le compte eCampus de ce dossier est en cours de traitement : réessayez dans une minute.');
        }

        return $resultat;
    }

    private function convertirSousVerrou(AcmaDossier $dossier, Candidature $dossierPlateforme, string $motif, User $auteur, bool $identiteConfirmee): Candidature
    {
        try {
            return DB::transaction(function () use ($dossier, $dossierPlateforme, $motif, $auteur, $identiteConfirmee): Candidature {
                $c = Candidature::query()->whereKey($dossierPlateforme->id)->lockForUpdate()->firstOrFail();
                $d = AcmaDossier::query()->whereKey($dossier->id)->lockForUpdate()->firstOrFail();
                $this->prealables($d, $c, $identiteConfirmee);

                $ancien = $c->matricule;
                $mode = $ancien === null ? ReinscriptionAcma::ATTRIBUTION : ReinscriptionAcma::CONVERSION;
                $auditeur = $mode === ReinscriptionAcma::ATTRIBUTION ? $this->attribuer($c, $d) : $c->auditeur()->firstOrFail();
                $rapprochement = LienArchive::poser($d, $auditeur, 'Matricule ACMA repris par le dossier '.$c->numero_dossier.' : '.$motif, $auteur);

                $trace = new ReinscriptionAcma;
                $trace->forceFill([
                    'dossier_acma_id' => $d->id, 'candidature_id' => $c->id, 'auditeur_id' => $auditeur->id,
                    'inscription_annuelle_id' => $auditeur->inscriptionCourante()->value('id'), 'campagne_id' => $c->campagne_id,
                    'rapprochement_id' => $rapprochement, 'mode' => $mode, 'ancien_matricule' => $ancien, 'motif' => $motif, 'auteur_id' => $auteur->id,
                ])->save();

                if ($mode === ReinscriptionAcma::CONVERSION) {
                    $c->forceFill([
                        'matricule' => $d->matricule,
                        // Compte eCampus déjà créé sous l'ancien matricule : son identifiant ne change pas.
                        'identifiant_ecampus' => AccesEcampus::compteExiste($c) ? IdentifiantsMoodle::identifiant($c) : null,
                    ])->save();
                }

                activity(Journaux::SCOLARITE)->causedBy($auteur)->performedOn($c)->event(self::EVENEMENT)
                    ->withProperties(['mode' => $mode, 'ancien' => $ancien, 'nouveau' => $d->matricule, 'dossier' => $d->uuid, 'motif' => $motif])
                    ->log('Matricule ACMA repris (le dossier ACMA fait foi)');
                activity(Journaux::ARCHIVES)->causedBy($auteur)->event('rapprochement_decide')
                    ->withProperties(['dossier' => $d->uuid, 'decision' => 'lie', 'origine' => 'matricule_acma'])
                    ->log('Rapprochement d\'un dossier d\'archive ACMA décidé par la reprise du matricule');

                return $c->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Ce matricule ou ce dossier vient d\'être repris par ailleurs. Vérifiez, puis réessayez.');
        }
    }

    private function prealables(AcmaDossier $d, Candidature $c, bool $identiteConfirmee): void
    {
        if ($d->retire_de_la_source_at !== null || $d->statut_rapprochement === 'ecarte') {
            throw new InvalidArgumentException('Dossier d\'archive disparu de l\'export ou écarté : matricule non repris.');
        }
        if (ReinscriptionAcma::query()->where('dossier_acma_id', $d->id)->orWhere('candidature_id', $c->id)->exists()) {
            throw new InvalidArgumentException('Ce dossier d\'archive ou ce dossier de la plateforme a déjà repris un matricule ACMA.');
        }
        if ($c->statut !== Candidature::STATUT_ACCEPTE || $c->withdrawn_at !== null) {
            throw new InvalidArgumentException('Seul un retenu non retiré peut reprendre un matricule ACMA.');
        }
        if ($c->matricule === $d->matricule) {
            throw new InvalidArgumentException('Ce dossier porte déjà le matricule ACMA : rien à reprendre.');
        }
        // Un matricule déjà attribué ne change que si l'on sait où en est le compte eCampus (créé, existant ou à finir) :
        // sinon, une création ultérieure partirait sous le nouveau matricule et l'ancien compte serait orphelin.
        if ($c->matricule !== null && in_array($c->ecampus_statut, [null, ComptesEcampus::ECHEC], true)) {
            throw new InvalidArgumentException('Compte eCampus de ce dossier inconnu ou en échec : rapprochez-le d\'abord (ecampus:rapprocher, ou bouton eCampus de la fiche), puis reprenez le matricule.');
        }
        $auditeur = $c->auditeur;
        if ($d->auditeur_id !== null && ($auditeur === null || (int) $d->auditeur_id !== (int) $auditeur->id)) {
            throw new InvalidArgumentException('Ce dossier d\'archive est lié à un autre auditeur : arbitrez d\'abord.');
        }
        if (Candidature::withTrashed()->where('matricule', $d->matricule)->whereKeyNot($c->id)->exists() || ReinscriptionAncien::matriculeDejaUtilise($d->matricule)) {
            throw new InvalidArgumentException("Le matricule {$d->matricule} est déjà porté par un autre dossier.");
        }
        $naissance = ReinscriptionAncien::dateNaissanceArchive($d);
        $noms = ComparaisonIdentite::score(NormalisationAcma::jetons($d->nom_complet), NormalisationAcma::jetons($c->nom, $c->prenom, $c->epouse));
        if (($naissance !== $c->date_naissance?->toDateString() || ! ComparaisonIdentite::compatibles($noms)) && ! $identiteConfirmee) {
            throw new InvalidArgumentException('L\'identité de l\'archive et celle du dossier ne concordent pas (date de naissance ou noms) : vérifiez la pièce, puis confirmez.');
        }
    }

    /** Mode `attribution` : matricule ACMA sans rang, mot de passe FOAD, puis ouverture de l'auditeur comme à l'attribution. */
    private function attribuer(Candidature $c, AcmaDossier $d): Auditeur
    {
        $c->forceFill([
            'matricule' => $d->matricule,
            'categorie_matricule' => str_contains($d->matricule, Candidature::CATEGORIE_EFSC) ? Candidature::CATEGORIE_EFSC : Candidature::CATEGORIE_EFP,
            'rang_retenu' => null,
            'moodle_password' => AttributionMatricules::motDePasse(),
            'matricule_attribue_at' => now(),
        ])->save();

        return $this->ouverture->ouvrir($c);
    }
}
