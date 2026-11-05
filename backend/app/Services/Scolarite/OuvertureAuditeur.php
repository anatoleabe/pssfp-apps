<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Crée l'auditeur et son inscription à l'attribution du matricule. Idempotent.
 *
 * Un barème absent ne bloque jamais l'attribution : l'auditeur est créé sans
 * inscription, signalé dans la liste, et la fiche propose de la créer.
 */
final class OuvertureAuditeur
{
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
            abort_unless($auteur->can(RegistreVersements::PERMISSION_SAISIR), 403);
        }
        $dossier = $auditeur->candidature()->with('campagne')->firstOrFail();
        if ($auteur !== null && $dossier->withdrawn_at !== null) {
            throw new InvalidArgumentException('Ce dossier est retiré : aucune inscription ne peut être créée.');
        }
        $annee = $dossier->campagne->anneeAcademique();

        $existante = InscriptionAnnuelle::query()->where('auditeur_id', $auditeur->id)->where('annee_academique', $annee)->first();
        if ($existante !== null) {
            return $existante;
        }

        // Une arrivée directe en M2 passera par l'import dédié : ici, toujours M1.
        $bareme = BaremeScolarite::query()->where('annee_academique', $annee)->where('niveau', BaremeScolarite::NIVEAU_M1)->first();
        if ($bareme === null) {
            return null;
        }

        try {
            $inscription = InscriptionAnnuelle::query()->create([
                'auditeur_id' => $auditeur->id,
                'bareme_id' => $bareme->id,
                'annee_academique' => $annee,
                'niveau' => $bareme->niveau,
                'montant_inscription' => $bareme->montant_inscription,
                'montant_tranche1' => $bareme->montant_tranche1,
                'montant_tranche2' => $bareme->montant_tranche2,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Double clic : l'autre requête a créé l'inscription.
            return InscriptionAnnuelle::query()->where('auditeur_id', $auditeur->id)->where('annee_academique', $annee)->first();
        }
        activity('scolarite')->causedBy($auteur)->performedOn($inscription)
            ->event('inscription_creee')
            ->withProperties(['annee' => $annee, 'niveau' => $bareme->niveau, 'dossier' => $dossier->numero_dossier])
            ->log('Inscription annuelle créée');
        $this->reprendrePointage($inscription, $dossier);
        $auditeur->unsetRelation('inscriptionCourante');

        return $inscription;
    }

    /**
     * Une case déjà cochée devient un versement du montant de l'inscription, à
     * compléter par la Scolarité : cocher voulait dire « au moins ce montant ».
     */
    public function reprendrePointage(InscriptionAnnuelle $inscription, Candidature $dossier): void
    {
        if ($dossier->frais_inscription_payes_at === null || filled($dossier->matricule) === false) {
            return;
        }

        $reference = 'REPRISE-'.$dossier->matricule;
        if (Versement::query()->where('mode', Versement::MODE_REPRISE)->where('reference', $reference)->exists()) {
            return;
        }

        $versement = new Versement;
        $versement->forceFill([
            'inscription_annuelle_id' => $inscription->id,
            'montant' => $inscription->montant_inscription,
            'date_versement' => $dossier->frais_inscription_payes_at->copy()->timezone(InscriptionAnnuelle::FUSEAU)->toDateString(),
            'mode' => Versement::MODE_REPRISE,
            'reference' => $reference,
            'payeur' => 'auditeur',
            'saisi_par' => $dossier->frais_inscription_payes_by,
        ])->save();

        activity('scolarite')->performedOn($versement)
            ->event('versement_repris')
            ->withProperties(['montant' => $versement->montant, 'reference' => $reference])
            ->log('Case « droits payés » reprise en versement');
    }
}
