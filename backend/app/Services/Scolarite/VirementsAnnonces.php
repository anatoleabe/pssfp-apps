<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Models\VirementAnnonce;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Virements en deux temps (spec virements-bordereaux §2) : le service financier annonce le virement (hors compte, sans
 * reçu), puis le valide à sa date de paiement (RegistreVersements::encaisserVirementAnnonce) ou l'abandonne.
 */
final class VirementsAnnonces
{
    /** Un virement annoncé reste en attente au plus ce nombre de jours avant d'alerter le contrôle interne. */
    public const ATTENTE_MAX_JOURS = 15;

    public static function peutAnnoncer(?User $user): bool
    {
        return (bool) $user?->can(RegistreVersements::PERMISSION_SAISIR);
    }

    public static function peutValider(?User $user): bool
    {
        return (bool) ($user?->can(RegistreVersements::PERMISSION_SAISIR) && $user->can(RecusVersements::PERMISSION_EMETTRE));
    }

    /** @param  array<string, mixed>  $saisie  montant, date_versement (date de l'ordre), reference, payeur, justificatif_path */
    public function annoncer(InscriptionAnnuelle $inscription, array $saisie, User $auteur): VirementAnnonce
    {
        abort_unless(self::peutAnnoncer($auteur), 403);
        $montant = filter_var($saisie['montant'] ?? null, FILTER_VALIDATE_INT);
        if ($montant === false || $montant <= 0 || $montant > RegistreVersements::MONTANT_MAX) {
            throw new InvalidArgumentException('Le montant doit être un nombre entier de FCFA, supérieur à zéro, sans dépasser '
                .number_format(RegistreVersements::MONTANT_MAX, 0, ',', ' ').' FCFA.');
        }
        if (blank($saisie['date_versement'] ?? null)) {
            throw new InvalidArgumentException('Indiquez la date de l\'ordre de virement.');
        }
        $date = CarbonImmutable::parse((string) $saisie['date_versement'], InscriptionAnnuelle::FUSEAU)->startOfDay();
        if ($date->greaterThan(InscriptionAnnuelle::aujourdhui())) {
            throw new InvalidArgumentException('La date de l\'ordre de virement ne peut pas être dans le futur.');
        }
        $payeur = (string) ($saisie['payeur'] ?? '');
        if (! array_key_exists($payeur, Versement::PAYEURS)) {
            throw new InvalidArgumentException('Choisissez un payeur de la liste.');
        }
        $reference = Versement::normaliserReference((string) ($saisie['reference'] ?? ''));
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw new InvalidArgumentException('Indiquez la référence du virement (100 caractères au plus).');
        }
        $chemin = $saisie['justificatif_path'] ?? null;
        if (! is_string($chemin) || ! Versement::cheminJustificatifValide($chemin)) {
            throw new InvalidArgumentException('Joignez le scan de l\'ordre ou de l\'avis de virement : il est obligatoire.');
        }
        if (Versement::pieceDejaJointe($chemin)) {
            throw new InvalidArgumentException('Ce scan est déjà joint ailleurs : déposez la pièce propre à ce virement.');
        }
        if (Versement::query()->where('mode', 'virement')->where('reference', $reference)->whereNull('annule_at')->exists()) {
            throw new InvalidArgumentException('Un versement par virement porte déjà la référence '.$reference.'.');
        }
        $dossier = $inscription->auditeur()->with('candidature')->first()?->candidature;
        if ($dossier === null || $dossier->withdrawn_at !== null) {
            throw new InvalidArgumentException('Ce dossier est retiré : aucune nouvelle écriture n\'est possible.');
        }

        try {
            return DB::transaction(function () use ($inscription, $montant, $date, $reference, $payeur, $chemin, $auteur): VirementAnnonce {
                $a = new VirementAnnonce;
                $a->forceFill([
                    'auditeur_id' => $inscription->auditeur_id, 'inscription_annuelle_id' => $inscription->id, 'montant' => $montant,
                    'reference' => $reference, 'payeur' => $payeur, 'date_ordre' => $date->toDateString(), 'justificatif_path' => $chemin,
                    'saisi_par' => $auteur->id, 'statut' => VirementAnnonce::EN_ATTENTE,
                ])->save();
                activity('scolarite')->causedBy($auteur)->performedOn($a)->event('virement_annonce')
                    ->withProperties(['montant' => $montant, 'reference' => $reference])->log('Virement annoncé, en attente de crédit');

                return $a;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Un virement en attente porte déjà la référence '.$reference.'.');
        }
    }

    public function abandonner(VirementAnnonce $annonce, string $motif, User $auteur): void
    {
        abort_unless(self::peutAnnoncer($auteur), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < 10 || mb_strlen($motif) > 500) {
            throw new InvalidArgumentException('Indiquez pourquoi le virement est abandonné (de 10 à 500 caractères).');
        }
        DB::transaction(function () use ($annonce, $motif, $auteur): void {
            $a = VirementAnnonce::query()->whereKey($annonce->id)->lockForUpdate()->firstOrFail();
            if ($a->statut !== VirementAnnonce::EN_ATTENTE) {
                throw new InvalidArgumentException('Ce virement n\'est plus en attente.');
            }
            $a->forceFill(['statut' => VirementAnnonce::ABANDONNE, 'motif_abandon' => $motif, 'traite_par' => $auteur->id, 'traite_le' => now()])->save();
            activity('scolarite')->causedBy($auteur)->performedOn($a)->event('virement_abandonne')
                ->withProperties(['motif' => $motif])->log('Virement annoncé abandonné');
        });
    }

    /** @return Builder<VirementAnnonce> */
    public static function enAttente(): Builder
    {
        return VirementAnnonce::query()->where('statut', VirementAnnonce::EN_ATTENTE);
    }

    /**
     * Virements enregistrés avant ce circuit, sans reçu en vigueur (§2.7) : leur reçu s'émet à la date enregistrée.
     *
     * @return Builder<Versement>
     */
    public static function enregistresSansRecu(): Builder
    {
        return Versement::query()->where('mode', 'virement')->whereNull('annule_at')
            ->whereDoesntHave('recus', fn (Builder $q) => $q->whereDoesntHave('annulation'))
            ->whereDoesntHave('auditeur.candidature', fn (Builder $q) => $q->whereNotNull('withdrawn_at'));
    }
}
