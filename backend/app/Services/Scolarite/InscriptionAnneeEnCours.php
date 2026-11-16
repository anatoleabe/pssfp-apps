<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\Parcours;
use App\Models\User;
use App\Services\Lmd\InscriptionsPedagogiques;
use InvalidArgumentException;

/**
 * Inscription d'un auditeur dans l'année en cours, en M1 ou en M2 (spec de la réinscription des anciens, §7.5,
 * étape 5). Service que la réinscription en groupe (D3) réutilisera.
 *
 * - Toujours l'année en cours : jamais une année passée ni l'année suivante.
 * - Barème de l'année et du niveau copié ; parcours posé (TC en M1, option en M2).
 * - Sous le verrou de l'auditeur (ADR-0013, R8), puis R3 : l'avance du compte s'impute.
 * - `acces_notifie_at` reste vide : l'avis d'accès partira, une fois, quand l'accès s'ouvrira (paiement confirmé ou
 *   déblocage), par le chemin normal. Aucun envoi ici.
 */
final class InscriptionAnneeEnCours
{
    public function __construct(
        private readonly ImputationsCompte $imputations,
        private readonly InscriptionsPedagogiques $pedagogiques,
    ) {}

    /** @throws InvalidArgumentException message destiné à l'agent */
    public function inscrire(Auditeur $auditeur, string $niveau, Parcours $parcours, ?User $auteur, string $declencheur = 'reinscription_acma'): InscriptionAnnuelle
    {
        $annee = AnneeEnCours::annee() ?? throw new InvalidArgumentException('Année en cours non réglée : aucune inscription possible.');
        if (! in_array($niveau, [BaremeScolarite::NIVEAU_M1, BaremeScolarite::NIVEAU_M2], true)) {
            throw new InvalidArgumentException('Niveau inconnu : M1 ou M2.');
        }
        if ($parcours->niveau !== $niveau || ! $parcours->actif) {
            throw new InvalidArgumentException('Ce parcours n\'est pas un parcours actif du niveau '.$niveau.'.');
        }
        if ($niveau === BaremeScolarite::NIVEAU_M1 && $parcours->code !== Parcours::TRONC_COMMUN) {
            throw new InvalidArgumentException('En M1, le parcours est le tronc commun.');
        }
        $bareme = BaremeScolarite::query()->where('annee_academique', $annee)->where('niveau', $niveau)->first()
            ?? throw new InvalidArgumentException("Aucun barème {$niveau} pour {$annee} : saisissez-le d'abord (Vie académique > Barèmes).");

        $inscription = $this->imputations->sousVerrou((int) $auditeur->id, function (ImputationsCompte $compte) use ($auditeur, $annee, $bareme, $parcours, $auteur, $declencheur): InscriptionAnnuelle {
            if (InscriptionAnnuelle::query()->where('auditeur_id', $auditeur->id)->where('annee_academique', $annee)->exists()) {
                throw new InvalidArgumentException("Cet auditeur est déjà inscrit en {$annee}.");
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
            $inscription->forceFill(['parcours_id' => $parcours->id])->save();
            activity('scolarite')->causedBy($auteur)->performedOn($inscription)
                ->event('inscription_creee')
                ->withProperties(['annee' => $annee, 'niveau' => $bareme->niveau, 'parcours' => $parcours->code, 'origine' => $declencheur])
                ->log('Inscription annuelle créée');
            $compte->imputerAvance((int) $auditeur->id, $declencheur, $auteur);

            return $inscription;
        });

        // Lot 4 : inscription pédagogique dès que la maquette du parcours est validée (sans effet sinon).
        $this->pedagogiques->inscrire($inscription);
        $auditeur->unsetRelation('inscriptionCourante');

        return $inscription;
    }
}
