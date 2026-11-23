<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Lmd\InscriptionsPedagogiques;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Crée l'auditeur et son inscription à l'attribution du matricule. Idempotent.
 *
 * Un barème absent ne bloque jamais l'attribution : l'auditeur est créé sans
 * inscription, signalé dans la liste, et la fiche propose de la créer.
 *
 * L'inscription se crée sous le verrou de l'auditeur (ADR-0013, R8) : la reprise du
 * pointage y passe par R2, puis l'avance du compte s'impute sur l'inscription (R3).
 * Aucun envoi : l'ouverture ne prévient pas l'auditeur (la reprise du lot 1 en dépend).
 */
final class OuvertureAuditeur
{
    public function __construct(private readonly ImputationsCompte $imputations) {}

    public function ouvrir(Candidature $dossier): Auditeur
    {
        $auditeur = Auditeur::query()->firstOrCreate(['candidature_id' => $dossier->id]);
        $this->creerInscription($auditeur);

        return $auditeur;
    }

    /**
     * `$auteur` : l'agent qui crée l'inscription depuis la fiche (droit vérifié,
     * dossier retiré refusé) ; null pour l'attribution du matricule et la reprise.
     *
     * @return InscriptionAnnuelle|null null si le barème de l'année n'existe pas encore
     *
     * @throws InvalidArgumentException dossier retiré
     */
    public function creerInscription(Auditeur $auditeur, ?User $auteur = null): ?InscriptionAnnuelle
    {
        if ($auteur !== null) {
            // Administration et scolarité seulement, plus le service financier (décision du 23/11/2026, spec lot 22).
            abort_unless(ReinscriptionAuditeur::peutReinscrire($auteur), 403);
        }
        $dossier = $auditeur->candidature()->with('campagne')->firstOrFail();
        if ($auteur !== null && $dossier->withdrawn_at !== null) {
            throw new InvalidArgumentException('Ce dossier est retiré : aucune inscription ne peut être créée.');
        }
        $annee = $dossier->campagne->anneeAcademique();

        try {
            // Sous le verrou : un double clic attend, puis retrouve l'inscription créée.
            [$inscription, $creee] = $this->imputations->sousVerrou((int) $auditeur->id, function (ImputationsCompte $compte) use ($auditeur, $auteur, $dossier, $annee): array {
                $existante = InscriptionAnnuelle::query()->where('auditeur_id', $auditeur->id)->where('annee_academique', $annee)->first();
                if ($existante !== null) {
                    return [$existante, false];
                }

                // Une arrivée directe en M2 passera par l'import dédié : ici, toujours M1.
                $bareme = BaremeScolarite::query()->where('annee_academique', $annee)->where('niveau', BaremeScolarite::NIVEAU_M1)->first();
                if ($bareme === null) {
                    return [null, false];
                }

                $inscription = InscriptionAnnuelle::query()->create([
                    'auditeur_id' => $auditeur->id,
                    'bareme_id' => $bareme->id,
                    'annee_academique' => $annee,
                    'niveau' => $bareme->niveau,
                    'montant_inscription' => $bareme->montant_inscription,
                    'montant_tranche1' => $bareme->montant_tranche1,
                    'montant_tranche2' => $bareme->montant_tranche2,
                ]);
                activity('scolarite')->causedBy($auteur)->performedOn($inscription)
                    ->event('inscription_creee')
                    ->withProperties(['annee' => $annee, 'niveau' => $bareme->niveau, 'dossier' => $dossier->numero_dossier])
                    ->log('Inscription annuelle créée');
                $this->reprendrePointage($inscription, $dossier);
                // R3 : l'avance du compte s'impute sur la nouvelle inscription, sans SMS (D3 l'ajoutera).
                $compte->imputerAvance((int) $auditeur->id, 'ouverture', $auteur);

                return [$inscription, true];
            });
        } catch (UniqueConstraintViolationException) {
            // Création concurrente hors du verrou (import) : l'autre requête a créé l'inscription.
            return InscriptionAnnuelle::query()->where('auditeur_id', $auditeur->id)->where('annee_academique', $annee)->first();
        }
        if (! $creee) {
            return $inscription;
        }
        // Lot 4 : inscription pédagogique dès que la maquette du parcours est validée (sans effet sinon).
        app(InscriptionsPedagogiques::class)->inscrire($inscription);
        $auditeur->unsetRelation('inscriptionCourante');

        return $inscription;
    }

    /**
     * Une case déjà cochée devient un versement du montant de l'inscription, à
     * compléter par la Scolarité : cocher voulait dire « au moins ce montant ».
     * Imputé comme tout versement (R2), sous le verrou de l'auditeur.
     */
    public function reprendrePointage(InscriptionAnnuelle $inscription, Candidature $dossier): void
    {
        if ($dossier->frais_inscription_payes_at === null || filled($dossier->matricule) === false) {
            return;
        }

        $this->imputations->sousVerrou((int) $inscription->auditeur_id, function (ImputationsCompte $compte) use ($inscription, $dossier): void {
            $reference = 'REPRISE-'.$dossier->matricule;
            if (Versement::query()->where('mode', Versement::MODE_REPRISE)->where('reference', $reference)->exists()) {
                return;
            }

            $versement = new Versement;
            $versement->forceFill([
                'auditeur_id' => $inscription->auditeur_id,
                'inscription_annuelle_id' => $inscription->id,
                'montant' => $inscription->montant_inscription,
                'date_versement' => $dossier->frais_inscription_payes_at->copy()->timezone(InscriptionAnnuelle::FUSEAU)->toDateString(),
                'mode' => Versement::MODE_REPRISE,
                'reference' => $reference,
                'payeur' => 'auditeur',
                'saisi_par' => $dossier->frais_inscription_payes_by,
            ])->save();
            // La case cochée était déjà la vérification de la scolarité : le versement repris est confirmé (15/11/2026).
            ConfirmationsVersements::poserReprise($versement, $dossier->frais_inscription_payes_by, $dossier->frais_inscription_payes_at);
            $plan = $compte->apresVersement($versement);

            activity('scolarite')->performedOn($versement)
                ->event('versement_repris')
                ->withProperties(['montant' => $versement->montant, 'reference' => $reference,
                    'imputations' => $compte->decrire($plan), 'avance' => $versement->montant - $plan->montantImpute()])
                ->log('Case « droits payés » reprise en versement');
        });
    }
}
