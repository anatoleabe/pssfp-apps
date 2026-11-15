<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\ConfirmationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Confirmation des versements par la scolarité, sur présentation du reçu bancaire original (spec
 * module-4-scolarite-encaissement-confirmation §2.2, décision du 15/11/2026). Seul point d'écriture des
 * confirmations : scan du bordereau et attestation exigés, jamais un versement que l'agent a saisi,
 * une seule fois. La confirmation n'écrit pas d'argent : elle verrouille la ligne du versement (ce qui
 * la sérialise avec son annulation) et laisse l'index unique trancher entre deux agents. Après la
 * validation : régularisation de l'accès anticipé et SMS des accès, si les droits sont alors soldés.
 */
final class ConfirmationsVersements
{
    public const PERMISSION = 'scolarite.versement_confirmer';

    public const ATTESTATION = 'J\'atteste avoir vérifié le reçu bancaire original : le montant a bien été versé';

    public function __construct(
        private readonly DeblocagesAcces $deblocages,
        private readonly NotificationAcces $notification,
    ) {}

    public static function peutConfirmer(?User $agent): bool
    {
        return (bool) $agent?->can(self::PERMISSION);
    }

    /** Le versement peut-il être confirmé par cet agent (hors scan et attestation) ? null si oui, sinon la raison. */
    public static function raisonRefus(Versement $v, ?User $agent): ?string
    {
        if (! self::peutConfirmer($agent)) {
            return 'Confirmer un versement est réservé à la scolarité.';
        }
        if ($v->estAnnule()) {
            return 'Ce versement est annulé : il ne se confirme pas.';
        }
        if ($v->saisi_par !== null && $v->saisi_par === $agent->id) {
            return 'Vous avez enregistré ce versement : un autre agent de la scolarité le confirme.';
        }
        if (ConfirmationVersement::query()->where('versement_id', $v->id)->exists()) {
            return 'Ce versement est déjà confirmé.';
        }

        return null;
    }

    /**
     * Confirme le versement ; la part d'un règlement d'organisme se confirme avec toutes les parts encore
     * à confirmer de son règlement, sur la pièce bancaire du règlement (§5 PR 2, point 4).
     *
     * @throws InvalidArgumentException message destiné à l'agent
     */
    public function confirmer(Versement $versement, ?string $scan, bool $attestation, User $agent): ResultatConfirmation
    {
        abort_unless(self::peutConfirmer($agent), 403);
        if (! $attestation) {
            throw new InvalidArgumentException('Cochez « '.self::ATTESTATION.' » : la confirmation l\'exige.');
        }
        if (blank($scan)) {
            throw new InvalidArgumentException('Déposez le scan du bordereau bancaire : la confirmation l\'exige.');
        }
        if (! Versement::cheminJustificatifValide($scan)) {
            throw new InvalidArgumentException('Le scan du bordereau est invalide : déposez-le à nouveau.');
        }
        if (Versement::pieceDejaJointe((string) $scan)) {
            throw new InvalidArgumentException('Ce scan est déjà joint à un autre versement ou à une autre pièce : déposez le bordereau propre à ce versement.');
        }

        $sms = null;
        try {
            $confirmations = DB::transaction(function () use ($versement, $scan, $agent, &$sms): array {
                $lignes = $this->aConfirmer($versement);
                $confirmations = [];
                foreach ($lignes as $v) {
                    $raison = self::raisonRefus($v, $agent);
                    if ($raison !== null) {
                        throw new InvalidArgumentException($raison);
                    }
                    $confirmations[] = $this->ecrire($v, (string) $scan, $agent, $lignes->count() === 1 && $v->reglement_organisme_id === null);
                }
                $inscriptions = $lignes->pluck('inscription_annuelle_id')->unique()->values()->all();
                DB::afterCommit(function () use ($inscriptions, $agent, &$sms): void {
                    $sms = $this->apres($inscriptions, $agent);
                });

                return $confirmations;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Ce versement vient d\'être confirmé par un autre agent.');
        }

        return new ResultatConfirmation($confirmations, $sms);
    }

    /**
     * Reprise : un versement déjà vérifié par la scolarité avant le 15/11/2026 (case « droits payés » du
     * pointage, lot 1) est confirmé dès sa création, sans scan ni attestation. Dans la transaction de l'appelant.
     */
    public static function poserReprise(Versement $versement, ?int $par, \DateTimeInterface $le): ConfirmationVersement
    {
        $c = (new ConfirmationVersement)->forceFill([
            'versement_id' => $versement->id, 'confirme_par' => $par, 'confirme_at' => $le, 'reprise' => true,
        ]);
        $c->save();

        return $c;
    }

    /**
     * Le versement, ou les parts encore à confirmer de son règlement, verrouillés dans l'ordre des id.
     *
     * @return Collection<int, Versement>
     */
    private function aConfirmer(Versement $versement): Collection
    {
        $courant = Versement::query()->whereKey($versement->id)->lockForUpdate()->firstOrFail();
        if ($courant->reglement_organisme_id === null) {
            return new Collection([$courant]);
        }
        $parts = Versement::query()->where('reglement_organisme_id', $courant->reglement_organisme_id)
            ->whereNull('annule_at')->whereDoesntHave('confirmation')
            ->orderBy('id')->lockForUpdate()->get();

        // La part désignée d'abord relue : déjà confirmée ou annulée, elle dit pourquoi on refuse.
        return $parts->contains('id', $courant->id) ? $parts : new Collection([$courant]);
    }

    private function ecrire(Versement $v, string $scan, User $agent, bool $joindre): ConfirmationVersement
    {
        $c = (new ConfirmationVersement)->forceFill([
            'versement_id' => $v->id,
            'confirme_par' => $agent->id,
            'confirme_at' => now(),
            'justificatif_path' => $scan,
            'attestation' => true,
            'reprise' => false,
        ]);
        $c->save();
        // Le versement sans bordereau reçoit le scan de la scolarité (D1 l'autorise) ; jamais les parts d'un règlement.
        if ($joindre) {
            Versement::query()->whereKey($v->id)->whereNull('justificatif_path')->update(['justificatif_path' => $scan]);
        }
        activity('scolarite')->causedBy($agent)->performedOn($v)->event('versement_confirme')
            ->withProperties(['montant' => $v->montant, 'reference' => $v->reference, 'reglement' => $v->reglement_organisme_id !== null])
            ->log('Versement confirmé par la scolarité, sur le reçu bancaire original');

        return $c;
    }

    /**
     * Après la validation : l'argent confirmé peut solder les droits. Régularisation de l'accès anticipé, puis
     * SMS et e-mail des accès, comme après un versement avant le 15/11/2026. Ne lève jamais.
     *
     * @param  list<int>  $inscriptions
     * @return bool|null true un SMS des accès est parti, false échec, null rien à envoyer
     */
    private function apres(array $inscriptions, User $agent): ?bool
    {
        $sms = null;
        foreach (InscriptionAnnuelle::query()->with('auditeur.candidature')->whereKey($inscriptions)->get() as $inscription) {
            try {
                $this->deblocages->regulariser($inscription, $agent);
            } catch (Throwable $e) {
                Log::channel('single')->error('Régularisation de l\'accès anticipé impossible après confirmation : la tâche quotidienne la rattrapera.', ['inscription' => $inscription->uuid, 'erreur' => $e::class]);
            }
            $dossier = $inscription->auditeur?->candidature;
            $envoi = $dossier === null ? null : $this->notification->siDisponible($dossier, $agent);
            $sms = $envoi === true || $sms === true ? true : ($envoi ?? $sms);
        }

        return $sms;
    }
}
