<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\AnnulationRecu;
use App\Models\InscriptionAnnuelle;
use App\Models\ReglementOrganisme;
use App\Models\User;
use App\Models\Versement;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Seul point d'écriture de l'argent : versements, annulations, remises.
 *
 * Chaque écriture se fait sous le verrou de l'auditeur (ADR-0013, R8), passe par la
 * permission du geste (même si un écran l'a déjà vérifiée) et finit au journal. Les
 * imputations du compte sont posées par ImputationsCompte, avant et après les écritures
 * d'ici, dans l'ordre qu'exige le déclencheur de cohérence. Le SMS part après la
 * validation de la transaction. Les exceptions InvalidArgumentException portent un
 * message destiné à l'agent.
 */
final class RegistreVersements
{
    public const PERMISSION_SAISIR = 'scolarite.versement_saisir';

    public const PERMISSION_ANNULER = 'scolarite.versement_annuler';

    public const PERMISSION_REMISE = 'scolarite.remise';

    public const PERMISSION_EXPORT = 'scolarite.export';

    public const PERMISSION_BAREME = 'scolarite.bareme';

    /** Refus de la base (déclencheur de cohérence, CHECK) : un défaut du code, jamais montré tel quel. */
    public const COMPTE_INCOHERENT = ImputationsCompte::COMPTE_INCOHERENT;

    /** Plafond d'un versement, le même que celui du formulaire. */
    public const MONTANT_MAX = 100_000_000;

    private ?User $auteurCourant = null;

    public function __construct(
        private readonly ImputationsCompte $imputations,
        private readonly NotificationAcces $notification,
        private readonly RecusVersements $recus,
        private readonly DeblocagesAcces $deblocages,
    ) {}

    /**
     * @param  array{montant: mixed, date_versement: mixed, mode: mixed, reference: mixed, payeur: mixed, justificatif_path?: ?string}  $saisie
     *
     * @throws InvalidArgumentException
     */
    public function enregistrer(InscriptionAnnuelle $inscription, array $saisie, User $auteur): ResultatOperation
    {
        abort_unless($auteur->can(self::PERMISSION_SAISIR), 403);

        [$montant, $date, $mode, $reference, $payeur, $justificatif] = $this->validerSaisie($inscription, $saisie);
        $versement = $this->ecrireVersement($inscription, $montant, $date, $mode, $reference, $payeur, $justificatif, $auteur, null);
        $this->auteurCourant = $auteur;

        return new ResultatOperation($versement, $this->notifier($inscription));
    }

    /**
     * « Enregistrer et émettre le reçu » (service financier, décision du 15/11/2026) : le versement et son
     * reçu s'écrivent sous le même verrou et dans la même transaction. Un reçu refusé n'enregistre rien.
     * L'avis du reçu et la notification des accès partent après la validation.
     *
     * @param  array{montant: mixed, date_versement: mixed, mode: mixed, reference: mixed, payeur: mixed, justificatif_path?: ?string}  $saisie
     *
     * @throws InvalidArgumentException
     */
    public function encaisser(InscriptionAnnuelle $inscription, array $saisie, User $auteur): ResultatOperation
    {
        abort_unless($auteur->can(self::PERMISSION_SAISIR) && $auteur->can(RecusVersements::PERMISSION_EMETTRE), 403);

        [$montant, $date, $mode, $reference, $payeur, $justificatif] = $this->validerSaisie($inscription, $saisie);
        [$versement, $recu] = $this->ecrire((int) $inscription->auditeur_id, function () use ($inscription, $montant, $date, $mode, $reference, $payeur, $justificatif, $auteur): array {
            $versement = $this->ecrireVersement($inscription, $montant, $date, $mode, $reference, $payeur, $justificatif, $auteur, null);

            return [$versement, $this->recus->emettre($versement, $auteur)];
        });
        $this->auteurCourant = $auteur;

        return new ResultatOperation($versement, $this->notifier($inscription), $recu);
    }

    /**
     * Contrôles de la saisie d'un versement, avant toute écriture.
     *
     * @param  array<string, mixed>  $saisie
     * @return array{int, CarbonImmutable, string, string, string, ?string} montant, date, mode, référence, payeur, scan
     *
     * @throws InvalidArgumentException
     */
    private function validerSaisie(InscriptionAnnuelle $inscription, array $saisie): array
    {
        $montant = filter_var($saisie['montant'] ?? null, FILTER_VALIDATE_INT);
        if ($montant === false || $montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être un nombre entier de FCFA, supérieur à zéro.');
        }
        if ($montant > self::MONTANT_MAX) {
            throw new InvalidArgumentException('Le montant ne peut pas dépasser '.number_format(self::MONTANT_MAX, 0, ',', ' ').' FCFA : vérifiez la saisie.');
        }
        if (blank($saisie['date_versement'] ?? null)) {
            throw new InvalidArgumentException('Indiquez la date du versement.');
        }
        $date = CarbonImmutable::parse((string) $saisie['date_versement'], InscriptionAnnuelle::FUSEAU)->startOfDay();
        if ($date->greaterThan(InscriptionAnnuelle::aujourdhui())) {
            throw new InvalidArgumentException('La date du versement ne peut pas être dans le futur.');
        }
        $mode = (string) ($saisie['mode'] ?? '');
        if (! array_key_exists($mode, Versement::MODES)) {
            throw new InvalidArgumentException('Choisissez un mode de paiement de la liste.');
        }
        $payeur = (string) ($saisie['payeur'] ?? '');
        if (! array_key_exists($payeur, Versement::PAYEURS)) {
            throw new InvalidArgumentException('Choisissez un payeur de la liste.');
        }
        $reference = Versement::normaliserReference((string) ($saisie['reference'] ?? ''));
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw new InvalidArgumentException('Indiquez la référence du bordereau (100 caractères au plus).');
        }
        $justificatif = $saisie['justificatif_path'] ?? null;
        if ($justificatif !== null) {
            self::verifierScan($justificatif);
        }
        $this->refuserDossierRetire($inscription);

        return [$montant, $date, $mode, $reference, $payeur, $justificatif];
    }

    /**
     * Part d'un règlement d'organisme (lot A2, §9.7) : un versement de l'employeur, à la date et dans
     * le mode du règlement, sans scan (la pièce est sur le règlement), imputé par R2 sur l'inscription
     * désignée. Appelé par ReglementsOrganismes seulement, dans sa transaction et sous ses verrous ;
     * rien n'est envoyé ici : l'appelant prévient après la validation (`apresReglement`).
     *
     * @throws InvalidArgumentException
     */
    public function enregistrerPartReglement(ReglementOrganisme $reglement, InscriptionAnnuelle $inscription, int $montant, string $reference, User $auteur): Versement
    {
        abort_unless($auteur->can(self::PERMISSION_SAISIR), 403);
        if ($montant <= 0 || $montant > self::MONTANT_MAX) {
            throw new InvalidArgumentException('Une part doit être comprise entre 1 et '.number_format(self::MONTANT_MAX, 0, ',', ' ').' FCFA.');
        }
        $reference = Versement::normaliserReference($reference);
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw new InvalidArgumentException('La référence '.$reference.' dépasse 100 caractères : raccourcissez la référence du règlement.');
        }
        $this->refuserDossierRetire($inscription);

        return $this->ecrireVersement($inscription, $montant, CarbonImmutable::parse($reglement->date_reglement->toDateString(), InscriptionAnnuelle::FUSEAU),
            (string) $reglement->mode, $reference, Versement::PAYEUR_EMPLOYEUR, null, $auteur, $reglement);
    }

    /** Après la validation d'un règlement : régularisation de l'accès anticipé et notification, comme après un versement. */
    public function apresReglement(InscriptionAnnuelle $inscription, User $auteur): ?bool
    {
        $this->auteurCourant = $auteur;

        return $this->notifier($inscription);
    }

    /**
     * Écrit un versement sous le verrou de son auditeur, l'impute (R2) et le journalise.
     *
     * @throws InvalidArgumentException
     */
    private function ecrireVersement(InscriptionAnnuelle $inscription, int $montant, CarbonImmutable $date, string $mode, string $reference,
        string $payeur, ?string $justificatif, User $auteur, ?ReglementOrganisme $reglement): Versement
    {
        try {
            return $this->ecrire((int) $inscription->auditeur_id, function (ImputationsCompte $compte) use ($inscription, $montant, $date, $mode, $reference, $payeur, $justificatif, $auteur, $reglement): Versement {
                if (Versement::query()->where('mode', $mode)->where('reference', $reference)->whereNull('annule_at')->exists()) {
                    throw new InvalidArgumentException(self::messageDoublon($reference));
                }
                // L'argent d'un organisme saisi en règlement ne se ressaisit pas en versement (lot A2).
                if ($reglement === null && ReglementOrganisme::query()->where('mode', $mode)->where('reference', $reference)->whereNull('annule_at')->exists()) {
                    throw new InvalidArgumentException("La référence {$reference} a déjà été saisie comme règlement d'un organisme pour ce mode de paiement (écran « Prises en charge ») : vérifiez le bordereau.");
                }

                $versement = new Versement;
                $versement->forceFill([
                    'auditeur_id' => $inscription->auditeur_id,
                    'inscription_annuelle_id' => $inscription->id,
                    'montant' => $montant,
                    'date_versement' => $date->toDateString(),
                    'mode' => $mode,
                    'reference' => $reference,
                    'payeur' => $payeur,
                    'justificatif_path' => $justificatif,
                    'saisi_par' => $auteur->id,
                    'reglement_organisme_id' => $reglement?->id,
                ])->save();
                $plan = $compte->apresVersement($versement);

                activity('scolarite')->causedBy($auteur)->performedOn($versement)
                    ->event('versement_enregistre')
                    ->withProperties(['montant' => $montant, 'mode' => $mode, 'reference' => $reference, 'inscription' => $inscription->uuid,
                        'imputations' => $compte->decrire($plan), 'avance' => $montant - $plan->montantImpute()]
                        + ($reglement === null ? [] : ['reglement' => $reglement->uuid]))
                    ->log($reglement === null ? 'Versement enregistré' : 'Versement enregistré (règlement d\'un organisme)');

                return $versement;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux agents sur le même bordereau, au même moment : l'index tranche.
            throw new InvalidArgumentException(self::messageDoublon($reference));
        }
    }

    /** @throws InvalidArgumentException */
    public function annuler(Versement $versement, string $motif, User $auteur): ResultatOperation
    {
        abort_unless($auteur->can(self::PERMISSION_ANNULER), 403);
        if ($versement->reglement_organisme_id !== null) {
            $r = ReglementOrganisme::query()->with('engagement:id,organisme')->find($versement->reglement_organisme_id);

            throw new InvalidArgumentException('Ce versement fait partie du règlement '.$r?->reference.' de '.$r?->engagement?->organisme
                .' : annulez le règlement entier depuis l\'écran « Prises en charge ».');
        }

        $this->annulerVersement($versement, $motif, $auteur, false);

        return new ResultatOperation($versement->refresh(), null);
    }

    /**
     * Annulation d'une part de règlement (lot A2, §9.7) : par ReglementsOrganismes seulement, dans sa
     * transaction et sous ses verrous. Mêmes règles que tout versement (R4 de D1, reçu annulé).
     *
     * @throws InvalidArgumentException
     */
    public function annulerPartReglement(Versement $versement, string $motif, User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION_ANNULER), 403);
        if ($versement->reglement_organisme_id === null) {
            throw new LogicException('Ce versement n\'est pas la part d\'un règlement.');
        }
        $this->annulerVersement($versement, $motif, $auteur, true);
    }

    /**
     * Correction d'un versement (ADR-0015, R-C6) : annule le versement erroné et son reçu, **sans** imputer l'avance
     * aussitôt (le remplaçant couvre le reste rouvert ; R3 vient une fois, après lui). Réservé à CorrectionsVersements,
     * qui vérifie les droits du demandeur et du décideur et appelle ce geste sous le verrou de l'auditeur.
     *
     * @return AnnulationRecu|null l'écriture d'annulation du reçu en vigueur, s'il y en avait un
     *
     * @throws InvalidArgumentException
     */
    public function annulerPourCorrection(Versement $versement, string $motif, User $auteur): ?AnnulationRecu
    {
        if ($versement->reglement_organisme_id !== null) {
            throw new InvalidArgumentException('Ce versement fait partie d\'un règlement d\'organisme : il se corrige avec le règlement entier.');
        }

        return $this->annulerVersement($versement, $motif, $auteur, false, false);
    }

    /**
     * Versement remplaçant d'une correction (ADR-0015) : mêmes contrôles que toute saisie, saisi au nom du demandeur.
     * Réservé à CorrectionsVersements, sous le verrou de l'auditeur ; rien n'est envoyé ici.
     *
     * @param  array{montant: mixed, date_versement: mixed, mode: mixed, reference: mixed, payeur: mixed, justificatif_path?: ?string}  $saisie
     *
     * @throws InvalidArgumentException
     */
    public function enregistrerRemplacant(InscriptionAnnuelle $inscription, array $saisie, User $auteur): Versement
    {
        [$montant, $date, $mode, $reference, $payeur, $justificatif] = $this->validerSaisie($inscription, $saisie);

        return $this->ecrireVersement($inscription, $montant, $date, $mode, $reference, $payeur, $justificatif, $auteur, null);
    }

    /** R3 après une correction : l'avance comble les restes rouverts, une seule fois, après le remplaçant. */
    public function imputerAvanceApresCorrection(int $auditeurId, User $auteur): void
    {
        $this->ecrire($auditeurId, fn (ImputationsCompte $compte) => $compte->imputerAvance($auditeurId, 'annulation', $auteur));
    }

    /** Après la validation d'une correction : régularisation de l'accès anticipé et notification, comme après un versement. */
    public function apresCorrection(InscriptionAnnuelle $inscription, User $auteur): ?bool
    {
        $this->auteurCourant = $auteur;

        return $this->notifier($inscription);
    }

    /** @throws InvalidArgumentException */
    private function annulerVersement(Versement $versement, string $motif, User $auteur, bool $partReglement, bool $imputerAvance = true): ?AnnulationRecu
    {
        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Indiquez le motif de l\'annulation.');
        }

        return $this->ecrire((int) $versement->auditeur_id, function (ImputationsCompte $compte) use ($versement, $motif, $auteur, $partReglement, $imputerAvance): ?AnnulationRecu {
            $courant = Versement::query()->whereKey($versement->id)->firstOrFail();
            if ($courant->estAnnule()) {
                throw new InvalidArgumentException('Ce versement est déjà annulé.');
            }
            // Relu sous le verrou : la part d'un règlement ne s'annule qu'avec lui (lot A2).
            if (($courant->reglement_organisme_id !== null) !== $partReglement) {
                throw new InvalidArgumentException($partReglement ? 'Ce versement n\'est pas la part d\'un règlement.'
                    : 'Ce versement fait partie d\'un règlement d\'organisme : annulez le règlement entier depuis l\'écran « Prises en charge ».');
            }
            // R4 : refus d'un versement imputé sur plusieurs années (D4), puis ses imputations d'abord.
            $plan = $compte->avantAnnulation($courant, $motif, $auteur);

            $modifies = Versement::query()->whereKey($versement->id)->whereNull('annule_at')->update([
                'annule_at' => now(),
                'annule_par' => $auteur->id,
                'annule_motif' => mb_substr($motif, 0, 500),
            ]);
            if ($modifies === 0) {
                throw new InvalidArgumentException('Ce versement est déjà annulé.');
            }
            // L'avance des autres versements comble les restes rouverts (sauf pour une correction : après le remplaçant).
            if ($imputerAvance) {
                $compte->imputerAvance((int) $versement->auditeur_id, 'annulation', $auteur);
            }

            // Le reçu en vigueur reçoit une écriture d'annulation (Scolarité lot 4) ; il n'est pas modifié.
            $annulationRecu = $this->recus->annulerPourVersement($versement, $motif, $auteur);

            activity('scolarite')->causedBy($auteur)->performedOn($versement)
                ->event('versement_annule')
                ->withProperties(['montant' => $versement->montant, 'reference' => $versement->reference, 'motif' => $motif,
                    'imputations_annulees' => self::decrireAnnulees($plan)])
                ->log('Versement annulé');

            return $annulationRecu;
        });
    }

    /**
     * Remplace la remise de l'inscription (0 la retire). L'ancienne valeur reste
     * au journal.
     *
     * @throws InvalidArgumentException
     */
    public function accorderRemise(InscriptionAnnuelle $inscription, int $montant, string $motif, User $auteur): ResultatOperation
    {
        abort_unless($auteur->can(self::PERMISSION_REMISE), 403);

        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Indiquez le motif de la remise.');
        }
        $total = $inscription->montant_inscription + $inscription->montant_tranche1 + $inscription->montant_tranche2;
        if ($montant < 0 || $montant > $total) {
            throw new InvalidArgumentException('Cette remise dépasse le total dû de '.number_format($total, 0, ',', ' ').' FCFA.');
        }
        $this->refuserDossierRetire($inscription);

        $this->ecrire((int) $inscription->auditeur_id, function (ImputationsCompte $compte) use ($inscription, $montant, $motif, $auteur): void {
            // Sous le verrou de l'auditeur ; la ligne de l'inscription se verrouille à son UPDATE (ordre R8).
            $courante = InscriptionAnnuelle::query()->whereKey($inscription->id)->firstOrFail();
            $ancienne = (int) $courante->remise_montant;
            // R5 : désimputer ce qui dépasserait le nouveau dû, avant d'écrire la remise.
            $plan = $compte->avantRemise($courante, $montant, $motif, $auteur);

            $courante->forceFill([
                'remise_montant' => $montant,
                'remise_motif' => mb_substr($motif, 0, 500),
                'remise_par' => $auteur->id,
                'remise_at' => now(),
            ])->save();
            $compte->imputerAvance((int) $inscription->auditeur_id, 'remise', $auteur);

            activity('scolarite')->causedBy($auteur)->performedOn($courante)
                ->event('remise_accordee')
                ->withProperties(['ancienne' => $ancienne, 'nouvelle' => $montant, 'motif' => $motif,
                    'desimpute' => $plan->montantDesimpute() - $plan->montantImpute()])
                ->log('Remise modifiée');
        });

        $this->auteurCourant = $auteur;

        return new ResultatOperation(null, $this->notifier($inscription));
    }

    /** Joint le scan d'un bordereau à un versement qui n'en a pas encore. */
    public function joindreJustificatif(Versement $versement, string $chemin, User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION_SAISIR), 403);

        self::verifierScan($chemin);
        $this->refuserDossierRetire($versement->inscription()->firstOrFail());

        $modifies = Versement::query()->whereKey($versement->id)
            ->whereNull('annule_at')->whereNull('justificatif_path')
            ->update(['justificatif_path' => $chemin]);
        if ($modifies === 0) {
            throw new InvalidArgumentException('Ce versement est annulé ou a déjà un bordereau.');
        }

        activity('scolarite')->causedBy($auteur)->performedOn($versement)
            ->event('justificatif_joint')->log('Bordereau joint au versement');
    }

    /**
     * L'annulation ferait-elle repasser l'inscription courante sous le seuil des droits ? Pour
     * avertir l'agent. Prévu par le planificateur (R4 puis R3), sans rien écrire. Faux pour un
     * versement que D1 refuse d'annuler (plusieurs années) : le refus est annoncé à part.
     */
    public static function annulationRebloque(Versement $versement): bool
    {
        $planificateur = new PlanificateurImputations;
        $etat = ImputationsCompte::etat((int) $versement->auditeur_id);
        if (! isset($etat->versements[$versement->id])) {
            return false;
        }
        try {
            $plan = $planificateur->annulation($etat, (int) $versement->id, 'prévision');
        } catch (AnnulationMultiAnnees) {
            return false;
        }

        return self::rebloque($etat, $planificateur->avance($plan->etat)->etat);
    }

    /** La nouvelle remise ferait-elle repasser l'inscription courante sous le seuil ? Prévu par R5 puis R3. */
    public static function remiseRebloque(InscriptionAnnuelle $inscription, int $nouvelleRemise): bool
    {
        $planificateur = new PlanificateurImputations;
        $etat = ImputationsCompte::etat((int) $inscription->auditeur_id);
        $plan = $planificateur->remise($etat, (int) $inscription->id, $nouvelleRemise, 'prévision');

        return self::rebloque($etat, $planificateur->avance($plan->etat)->etat);
    }

    /** @return list<string> années sur lesquelles le versement est imputé, croissantes */
    public static function anneesTouchees(Versement $versement): array
    {
        $etat = ImputationsCompte::etat((int) $versement->auditeur_id);

        return array_map(fn (int $id): string => $etat->inscription($id)->annee, $etat->inscriptionsTouchees((int) $versement->id));
    }

    private static function rebloque(EtatCompte $avant, EtatCompte $apres): bool
    {
        $courante = $avant->courante();

        return $courante !== null && $avant->droitsSoldes($courante->id) && ! $apres->droitsSoldes($courante->id);
    }

    /**
     * Écriture d'argent sous le verrou de l'auditeur. Un refus de la base y devient le message
     * COMPTE_INCOHERENT (ImputationsCompte::sousVerrou).
     *
     * @template T
     *
     * @param  Closure(ImputationsCompte): T  $geste
     * @return T
     */
    private function ecrire(int $auditeurId, Closure $geste): mixed
    {
        return $this->imputations->sousVerrou($auditeurId, $geste);
    }

    /** @return list<array{inscription: string, annee: string, montant: int}> */
    private static function decrireAnnulees(PlanImputations $plan): array
    {
        return array_map(function (Desimputation $d) use ($plan): array {
            $i = $plan->initial->inscription($plan->initial->imputations[$d->id]->inscription);

            return [
                'inscription' => (string) InscriptionAnnuelle::query()->whereKey($i->id)->value('uuid'),
                'annee' => $i->annee,
                'montant' => $d->montant,
            ];
        }, $plan->desimputations);
    }

    private function notifier(InscriptionAnnuelle $inscription): ?bool
    {
        $this->regulariser($inscription);
        $dossier = $inscription->auditeur()->with('candidature')->first()?->candidature;

        return $dossier === null ? null : $this->notification->siDisponible($dossier, $this->auteurCourant);
    }

    /**
     * Accès anticipé régularisé dès le solde des droits d'inscription. Après la
     * transaction et sans jamais faire échouer l'écriture de l'argent : la tâche
     * quotidienne rattrape un échec.
     */
    private function regulariser(InscriptionAnnuelle $inscription): void
    {
        try {
            $this->deblocages->regulariser($inscription, $this->auteurCourant);
        } catch (Throwable $e) {
            Log::channel('single')->error('Régularisation de l\'accès anticipé impossible : la tâche quotidienne la rattrapera.', ['inscription' => $inscription->uuid, 'erreur' => $e::class]);
        }
    }

    /**
     * Chemin du dossier des bordereaux, rattaché à rien : un scan ne sert qu'à un
     * versement (même garde que la pièce d'un accès anticipé).
     *
     * @throws InvalidArgumentException
     */
    private static function verifierScan(string $chemin): void
    {
        if (! Versement::cheminJustificatifValide($chemin)) {
            throw new InvalidArgumentException('Le scan du bordereau est invalide : déposez-le à nouveau.');
        }
        if (Versement::pieceDejaJointe($chemin)) {
            throw new InvalidArgumentException('Ce scan est déjà joint à un autre versement ou à un accès anticipé : déposez le bordereau propre à ce versement.');
        }
    }

    private function refuserDossierRetire(InscriptionAnnuelle $inscription): void
    {
        $dossier = $inscription->auditeur()->with('candidature')->first()?->candidature;
        if ($dossier === null || $dossier->withdrawn_at !== null) {
            throw new InvalidArgumentException('Ce dossier est retiré : aucune nouvelle écriture n\'est possible. L\'historique reste consultable.');
        }
    }

    private static function messageDoublon(string $reference): string
    {
        return "La référence {$reference} a déjà été saisie pour ce mode de paiement. Vérifiez le bordereau, ou annulez d'abord le versement erroné.";
    }
}
