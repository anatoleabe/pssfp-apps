<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Seul point d'écriture de l'argent : versements, annulations, remises.
 *
 * Chaque écriture se fait sous verrou de l'inscription, passe par la permission
 * du geste (même si un écran l'a déjà vérifiée) et finit au journal. Le SMS part
 * après la validation de la transaction. Les exceptions InvalidArgumentException
 * portent un message destiné à l'agent.
 */
final class RegistreVersements
{
    public const PERMISSION_SAISIR = 'scolarite.versement_saisir';

    public const PERMISSION_ANNULER = 'scolarite.versement_annuler';

    public const PERMISSION_REMISE = 'scolarite.remise';

    public const PERMISSION_EXPORT = 'scolarite.export';

    public const PERMISSION_BAREME = 'scolarite.bareme';

    private ?User $auteurCourant = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly NotificationAcces $notification,
        private readonly RecusVersements $recus,
    ) {}

    /**
     * @param  array{montant: mixed, date_versement: mixed, mode: mixed, reference: mixed, payeur: mixed, justificatif_path?: ?string}  $saisie
     *
     * @throws InvalidArgumentException
     */
    public function enregistrer(InscriptionAnnuelle $inscription, array $saisie, User $auteur): ResultatOperation
    {
        abort_unless($auteur->can(self::PERMISSION_SAISIR), 403);

        $montant = filter_var($saisie['montant'] ?? null, FILTER_VALIDATE_INT);
        if ($montant === false || $montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être un nombre entier de FCFA, supérieur à zéro.');
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
        if ($justificatif !== null && ! Versement::cheminJustificatifValide($justificatif)) {
            throw new InvalidArgumentException('Le scan du bordereau est invalide : déposez-le à nouveau.');
        }
        $this->refuserDossierRetire($inscription);

        try {
            $versement = $this->db->transaction(function () use ($inscription, $montant, $date, $mode, $reference, $payeur, $justificatif, $auteur): Versement {
                InscriptionAnnuelle::query()->whereKey($inscription->id)->lockForUpdate()->firstOrFail();

                if (Versement::query()->where('mode', $mode)->where('reference', $reference)->whereNull('annule_at')->exists()) {
                    throw new InvalidArgumentException(self::messageDoublon($reference));
                }

                $versement = new Versement;
                $versement->forceFill([
                    'inscription_annuelle_id' => $inscription->id,
                    'montant' => $montant,
                    'date_versement' => $date->toDateString(),
                    'mode' => $mode,
                    'reference' => $reference,
                    'payeur' => $payeur,
                    'justificatif_path' => $justificatif,
                    'saisi_par' => $auteur->id,
                ])->save();

                activity('scolarite')->causedBy($auteur)->performedOn($versement)
                    ->event('versement_enregistre')
                    ->withProperties(['montant' => $montant, 'mode' => $mode, 'reference' => $reference, 'inscription' => $inscription->uuid])
                    ->log('Versement enregistré');

                return $versement;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux agents sur le même bordereau, au même moment : l'index tranche.
            throw new InvalidArgumentException(self::messageDoublon($reference));
        }

        $this->auteurCourant = $auteur;

        return new ResultatOperation($versement, $this->notifier($inscription));
    }

    /** @throws InvalidArgumentException */
    public function annuler(Versement $versement, string $motif, User $auteur): ResultatOperation
    {
        abort_unless($auteur->can(self::PERMISSION_ANNULER), 403);

        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Indiquez le motif de l\'annulation.');
        }

        $this->db->transaction(function () use ($versement, $motif, $auteur): void {
            InscriptionAnnuelle::query()->whereKey($versement->inscription_annuelle_id)->lockForUpdate()->firstOrFail();

            $modifies = Versement::query()->whereKey($versement->id)->whereNull('annule_at')->update([
                'annule_at' => now(),
                'annule_par' => $auteur->id,
                'annule_motif' => mb_substr($motif, 0, 500),
            ]);
            if ($modifies === 0) {
                throw new InvalidArgumentException('Ce versement est déjà annulé.');
            }

            // Le reçu en vigueur reçoit une écriture d'annulation (Scolarité lot 4) ; il n'est pas modifié.
            $this->recus->annulerPourVersement($versement, $motif, $auteur);

            activity('scolarite')->causedBy($auteur)->performedOn($versement)
                ->event('versement_annule')
                ->withProperties(['montant' => $versement->montant, 'reference' => $versement->reference, 'motif' => $motif])
                ->log('Versement annulé');
        });

        return new ResultatOperation($versement->refresh(), null);
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

        $this->db->transaction(function () use ($inscription, $montant, $motif, $auteur): void {
            $verrouillee = InscriptionAnnuelle::query()->whereKey($inscription->id)->lockForUpdate()->firstOrFail();
            $ancienne = (int) $verrouillee->remise_montant;

            $verrouillee->forceFill([
                'remise_montant' => $montant,
                'remise_motif' => mb_substr($motif, 0, 500),
                'remise_par' => $auteur->id,
                'remise_at' => now(),
            ])->save();

            activity('scolarite')->causedBy($auteur)->performedOn($verrouillee)
                ->event('remise_accordee')
                ->withProperties(['ancienne' => $ancienne, 'nouvelle' => $montant, 'motif' => $motif])
                ->log('Remise modifiée');
        });

        $this->auteurCourant = $auteur;

        return new ResultatOperation(null, $this->notifier($inscription));
    }

    /** Joint le scan d'un bordereau à un versement qui n'en a pas encore. */
    public function joindreJustificatif(Versement $versement, string $chemin, User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION_SAISIR), 403);

        if (! Versement::cheminJustificatifValide($chemin)) {
            throw new InvalidArgumentException('Le scan du bordereau est invalide : déposez-le à nouveau.');
        }
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

    /** L'annulation ferait-elle repasser l'inscription sous le seuil ? Pour avertir l'agent. */
    public static function annulationRebloque(Versement $versement): bool
    {
        $inscription = $versement->inscription()->with('bareme')->firstOrFail();
        $avant = $inscription->situation();

        return $avant->inscriptionSoldee() && $avant->verse - $versement->montant < $avant->paliers[0]->du;
    }

    /** La nouvelle remise ferait-elle repasser l'inscription sous le seuil ? */
    public static function remiseRebloque(InscriptionAnnuelle $inscription, int $nouvelleRemise): bool
    {
        if (! $inscription->situation()->inscriptionSoldee()) {
            return false;
        }

        $projection = (clone $inscription);
        $projection->remise_montant = $nouvelleRemise;

        return ! $projection->situation()->inscriptionSoldee();
    }

    private function notifier(InscriptionAnnuelle $inscription): ?bool
    {
        $dossier = $inscription->auditeur()->with('candidature')->first()?->candidature;

        return $dossier === null ? null : $this->notification->siDisponible($dossier, $this->auteurCourant);
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
