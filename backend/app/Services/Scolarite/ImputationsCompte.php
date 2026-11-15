<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\ImputationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Seul code qui écrit les imputations du compte de l'auditeur (spec D1, §7.2 ; ADR-0013).
 *
 * Toute écriture d'argent passe par `sousVerrou` : une transaction (ou un point de
 * sauvegarde) qui commence par verrouiller la ligne de l'auditeur (R8), et un seul
 * auditeur. Chaque méthode charge l'état sous ce verrou, demande le plan au
 * planificateur et l'écrit dans l'ordre qu'exige le déclencheur de cohérence :
 * annulations d'abord, créations ensuite.
 *
 * RegistreVersements reste le seul à écrire versements et remises : il appelle ce
 * service avant et après ses propres écritures.
 */
final class ImputationsCompte
{
    public const DECLENCHEURS = ['ouverture', 'remise', 'annulation'];

    private ?int $auditeurVerrouille = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PlanificateurImputations $planificateur,
    ) {}

    /**
     * Exécute le geste sous le verrou de l'auditeur. Un geste imbriqué sur le même auditeur
     * reprend le verrou déjà tenu ; sur un autre, il est refusé.
     *
     * @template T
     *
     * @param  Closure(self): T  $geste
     * @return T
     */
    public function sousVerrou(int $auditeurId, Closure $geste): mixed
    {
        if ($this->auditeurVerrouille !== null) {
            if ($this->auditeurVerrouille !== $auditeurId) {
                throw new LogicException('Une écriture d\'argent ne verrouille qu\'un auditeur.');
            }

            return $geste($this);
        }

        return $this->db->transaction(function () use ($auditeurId, $geste): mixed {
            Auditeur::query()->whereKey($auditeurId)->lockForUpdate()->firstOrFail();
            $this->auditeurVerrouille = $auditeurId;
            try {
                return $geste($this);
            } finally {
                $this->auditeurVerrouille = null;
            }
        });
    }

    /** R2 : imputations d'un versement qui vient d'être créé, dans la même transaction. */
    public function apresVersement(Versement $versement): PlanImputations
    {
        $this->exigerVerrou((int) $versement->auditeur_id);
        $plan = $this->planificateur->versement(self::etat((int) $versement->auditeur_id), (int) $versement->id, $versement->saisi_par);
        $this->ecrire($plan, null);

        return $plan;
    }

    /**
     * Refus R4.1, puis annulation des imputations du versement, avant l'annulation du versement lui-même.
     *
     * @throws AnnulationMultiAnnees
     */
    public function avantAnnulation(Versement $versement, string $motif, User $auteur): PlanImputations
    {
        $this->exigerVerrou((int) $versement->auditeur_id);
        $plan = $this->planificateur->annulation(self::etat((int) $versement->auditeur_id), (int) $versement->id, $motif);
        $this->ecrire($plan, $auteur);

        return $plan;
    }

    /** R5 : désimputation de ce qui dépasserait le nouveau dû, avant d'écrire la remise. */
    public function avantRemise(InscriptionAnnuelle $inscription, int $nouvelleRemise, string $motif, User $auteur): PlanImputations
    {
        $this->exigerVerrou((int) $inscription->auditeur_id);
        $plan = $this->planificateur->remise(self::etat((int) $inscription->auditeur_id), (int) $inscription->id, $nouvelleRemise, $motif);
        $this->ecrire($plan, $auteur);

        return $plan;
    }

    /** R3 : imputation de l'avance après une ouverture d'inscription, une remise ou une annulation. */
    public function imputerAvance(int $auditeurId, string $declencheur, ?User $auteur): PlanImputations
    {
        if (! in_array($declencheur, self::DECLENCHEURS, true)) {
            throw new LogicException('Déclencheur inconnu : '.$declencheur);
        }
        $this->exigerVerrou($auditeurId);
        $plan = $this->planificateur->avance(self::etat($auditeurId));
        $this->ecrire($plan, $auteur);
        if ($plan->imputations !== []) {
            activity('scolarite')->causedBy($auteur)->performedOn(Auditeur::query()->findOrFail($auditeurId))
                ->event('avance_imputee')
                ->withProperties(['declencheur' => $declencheur, 'imputations' => $this->decrire($plan)])
                ->log('Avance imputée');
        }

        return $plan;
    }

    /**
     * Reprise des versements d'un auditeur (spec D1, §10.1), depuis la migration seulement.
     * Rien si l'auditeur a déjà une imputation, active ou annulée, ou si sa reprise est déjà
     * au journal (exonéré : aucune ligne possible) : idempotente.
     *
     * @return array{reprise: PlanImputations, avance: PlanImputations}|null
     */
    public function reprendre(int $auditeurId): ?array
    {
        $this->exigerVerrou($auditeurId);
        $auditeur = Auditeur::query()->findOrFail($auditeurId);
        $dejaRepris = Activity::query()->where('event', 'compte_repris')
            ->where('subject_type', $auditeur->getMorphClass())->where('subject_id', $auditeurId)->exists();
        if ($dejaRepris || ImputationVersement::query()->where('auditeur_id', $auditeurId)->exists()) {
            return null;
        }
        $reprise = $this->planificateur->reprise(self::etat($auditeurId));
        $this->ecrire($reprise, null);
        $avance = $this->planificateur->avance(self::etat($auditeurId));
        $this->ecrire($avance, null);
        $solde = $avance->etat->avance();
        if ($solde > 0) {
            activity('scolarite')->performedOn($auditeur)
                ->event('compte_repris')
                ->withProperties([
                    'imputations' => [...$this->decrire($reprise), ...$this->decrire($avance)],
                    'impute' => $reprise->montantImpute() + $avance->montantImpute(),
                    'avance' => $solde,
                ])
                ->log('Compte repris : excédent gardé en avance');
        }

        return ['reprise' => $reprise, 'avance' => $avance];
    }

    /** État du compte tel qu'en base : inscriptions, versements valides, imputations actives (3 requêtes). */
    public static function etat(int $auditeurId): EtatCompte
    {
        $inscriptions = InscriptionAnnuelle::query()->where('auditeur_id', $auditeurId)
            ->get(['id', 'annee_academique', 'montant_inscription', 'montant_tranche1', 'montant_tranche2', 'remise_montant'])
            ->map(fn (InscriptionAnnuelle $i): InscriptionCompte => new InscriptionCompte((int) $i->id, (string) $i->annee_academique,
                (int) $i->montant_inscription, (int) $i->montant_tranche1, (int) $i->montant_tranche2, (int) $i->remise_montant))
            ->all();
        $versements = Versement::query()->where('auditeur_id', $auditeurId)->whereNull('annule_at')
            ->get(['id', 'date_versement', 'montant', 'payeur', 'inscription_annuelle_id'])
            ->map(fn (Versement $v): VersementCompte => new VersementCompte((int) $v->id, $v->date_versement->toDateString(),
                (int) $v->montant, $v->estReserve(), (int) $v->inscription_annuelle_id))
            ->all();
        $imputations = ImputationVersement::query()->where('auditeur_id', $auditeurId)->whereNull('annule_at')
            ->get(['id', 'versement_id', 'inscription_annuelle_id', 'montant', 'origine', 'cree_par'])
            ->map(fn (ImputationVersement $x): ImputationCompte => new ImputationCompte((int) $x->id, (int) $x->versement_id,
                (int) $x->inscription_annuelle_id, (int) $x->montant, (string) $x->origine, $x->cree_par))
            ->all();

        return new EtatCompte(array_values($inscriptions), array_values($versements), array_values($imputations));
    }

    /**
     * Imputations du plan pour le journal : montants et uuid seulement.
     *
     * @return list<array{inscription: string, annee: string, montant: int}>
     */
    public function decrire(PlanImputations $plan): array
    {
        $ids = array_unique(array_map(fn (ImputationCompte $x): int => $x->inscription, $plan->imputations));
        $inscriptions = InscriptionAnnuelle::query()->whereKey($ids)->get(['id', 'uuid', 'annee_academique'])->keyBy('id');

        return array_map(fn (ImputationCompte $x): array => [
            'inscription' => (string) $inscriptions->get($x->inscription)?->uuid,
            'annee' => (string) $inscriptions->get($x->inscription)?->annee_academique,
            'montant' => $x->montant,
        ], $plan->imputations);
    }

    /** Annulations d'abord, créations ensuite : chaque instruction laisse un compte cohérent. */
    private function ecrire(PlanImputations $plan, ?User $auteur): void
    {
        $auditeurId = $this->auditeurVerrouille ?? throw new LogicException('Écriture d\'imputation hors du verrou de l\'auditeur.');
        foreach ($plan->desimputations as $d) {
            $modifiees = ImputationVersement::query()->whereKey($d->id)->where('auditeur_id', $auditeurId)->whereNull('annule_at')
                ->update(['annule_at' => now(), 'annule_par' => $auteur?->id, 'annule_motif' => mb_substr($d->motif, 0, 500)]);
            if ($modifiees !== 1) {
                throw new LogicException('Imputation introuvable ou déjà annulée : '.$d->id);
            }
        }
        foreach ($plan->imputations as $x) {
            $ligne = new ImputationVersement;
            $ligne->forceFill([
                'auditeur_id' => $auditeurId,
                'versement_id' => $x->versement,
                'inscription_annuelle_id' => $x->inscription,
                'montant' => $x->montant,
                'origine' => $x->origine,
                'remplace_id' => $x->remplace,
                'cree_par' => $x->creePar,
            ])->save();
        }
    }

    private function exigerVerrou(int $auditeurId): void
    {
        if ($this->auditeurVerrouille !== $auditeurId) {
            throw new LogicException('Les imputations s\'écrivent sous le verrou de leur auditeur (ImputationsCompte::sousVerrou).');
        }
    }
}
