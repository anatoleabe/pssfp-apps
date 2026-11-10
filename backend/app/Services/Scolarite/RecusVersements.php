<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\AnnulationRecu;
use App\Models\InscriptionAnnuelle;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Support\AppSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use NumberFormatter;

/**
 * Reçus des versements de scolarité (spec Scolarité lot 4 ; D7) : émission par le service financier,
 * numéro RCPT-AAAA-NNNNNN continu sur l'année civile, contenu figé, annulation par une écriture distincte.
 */
final class RecusVersements
{
    public const PERMISSION_EMETTRE = 'scolarite.recu_emettre';

    /** Versements enregistrés à partir de cette date (mise en service) : les antérieurs n'ont pas de reçu. */
    public const CLE_DEPUIS = 'scolarite.recus_depuis';

    private const LIBELLES_PALIERS = [Palier::INSCRIPTION => 'droits d\'inscription', Palier::TRANCHE1 => '1re tranche', Palier::TRANCHE2 => '2e tranche'];

    /** Le versement peut-il recevoir un reçu (hors permission) ? null si oui, sinon la raison. */
    public function raisonRefus(Versement $v, ?User $auteur = null): ?string
    {
        if ($auteur !== null && $v->saisi_par === $auteur->id) {
            return 'Vous avez enregistré ce versement : son reçu est émis par un autre agent du service financier.';
        }
        if ($v->estAnnule()) {
            return 'Ce versement est annulé : il ne reçoit pas de reçu.';
        }
        $depuis = $this->miseEnService();
        if ($depuis === null) {
            return 'La date de mise en service des reçus n\'est pas réglée : émission impossible.';
        }
        if ($v->created_at === null || $v->created_at->lt($depuis)) {
            return 'Ce versement a été enregistré avant la mise en service des reçus : il n\'en reçoit pas.';
        }
        if ($v->inscription?->auditeur?->candidature?->withdrawn_at !== null) {
            return 'Le dossier de cet auditeur est retiré : il ne reçoit plus de reçu.';
        }
        if ($v->recus()->whereDoesntHave('annulation')->exists()) {
            return 'Ce versement a déjà un reçu en vigueur.';
        }

        return null;
    }

    /**
     * Versements qui attendent leur reçu de la part de cet agent : la
     * traduction en requête de raisonRefus() (pastille du menu, tableau de
     * bord). Vide tant que la mise en service n'est pas réglée.
     *
     * @return Builder<Versement>
     */
    public function enAttente(User $agent): Builder
    {
        $depuis = $this->miseEnService();

        return Versement::query()
            ->when($depuis === null, fn (Builder $q) => $q->whereIn('id', []))
            ->when($depuis !== null, fn (Builder $q) => $q->where('created_at', '>=', $depuis))
            ->whereNull('annule_at')
            ->where(fn (Builder $q) => $q->whereNull('saisi_par')->orWhere('saisi_par', '<>', $agent->id))
            ->whereDoesntHave('recus', fn (Builder $q) => $q->whereDoesntHave('annulation'))
            ->whereDoesntHave('inscription.auditeur.candidature', fn (Builder $q) => $q->whereNotNull('withdrawn_at'));
    }

    public function emettre(Versement $versement, User $auteur): RecuVersement
    {
        abort_unless($auteur->can(self::PERMISSION_EMETTRE), 403);

        return DB::transaction(function () use ($versement, $auteur): RecuVersement {
            // Verrou de l'inscription : il garantit l'invariant « un seul reçu en vigueur par versement », que la base
            // ne peut pas exprimer (l'annulation vit dans une autre table). Toute création de reçu passe par ici.
            $inscription = InscriptionAnnuelle::query()->with(['auditeur.candidature', 'bareme'])->whereKey($versement->inscription_annuelle_id)->lockForUpdate()->firstOrFail();
            $v = Versement::query()->whereKey($versement->id)->firstOrFail();
            $raison = $this->raisonRefus($v, $auteur);
            if ($raison !== null) {
                throw new InvalidArgumentException($raison);
            }
            $calcul = $this->imputation($inscription, $v);
            $annee = (int) now('Africa/Douala')->format('Y');
            $sequence = $this->prochainNumero($annee);
            $dossier = $inscription->auditeur?->candidature;
            $recu = (new RecuVersement)->forceFill([
                'numero' => sprintf('RCPT-%d-%06d', $annee, $sequence),
                'annee_numero' => $annee,
                'sequence' => $sequence,
                'versement_id' => $v->id,
                'inscription_annuelle_id' => $inscription->id,
                'montant' => $v->montant,
                'objet' => $calcul['objet'],
                'auditeur_nom' => trim(mb_strtoupper((string) $dossier?->nom).' '.$dossier?->prenom),
                'matricule' => $dossier?->matricule,
                'annee_academique' => $inscription->annee_academique,
                'niveau' => $inscription->niveau,
                'mode' => $v->mode,
                'reference' => $v->reference,
                'date_versement' => $v->date_versement->toDateString(),
                'payeur' => $v->payeur,
                'total_verse' => $calcul['total_verse'],
                'reste_a_payer' => $calcul['reste'],
                'emis_par' => $auteur->id,
                'emetteur_nom' => $auteur->name,
                'emis_at' => now(),
            ]);
            $recu->save();
            activity('scolarite')->causedBy($auteur)->performedOn($v)->event('recu_emis')
                ->withProperties(['numero' => $recu->numero, 'montant' => $recu->montant])->log('Reçu émis');

            return $recu;
        });
    }

    /** Écriture d'annulation d'un reçu (le reçu reste intact). */
    public function annuler(RecuVersement $recu, string $motif, User $auteur): AnnulationRecu
    {
        abort_unless($auteur->can(self::PERMISSION_EMETTRE), 403);

        return $this->ecrireAnnulation($recu, $motif, $auteur);
    }

    /**
     * Appelé par l'annulation d'un versement : son reçu en vigueur reçoit une écriture d'annulation.
     * Un reçu annulé entre-temps par un autre agent n'empêche pas l'annulation du versement.
     */
    public function annulerPourVersement(Versement $versement, string $motifVersement, User $auteur): ?AnnulationRecu
    {
        $recu = $versement->recus()->whereDoesntHave('annulation')->first();
        if ($recu === null) {
            return null;
        }
        try {
            return $this->ecrireAnnulation($recu, 'Versement annulé : '.$motifVersement, $auteur);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Date de mise en service des reçus ; null si le réglage manque ou n'est pas une date (l'émission est alors refusée). */
    public function miseEnService(): ?CarbonImmutable
    {
        $v = AppSettings::get(self::CLE_DEPUIS);
        if (! is_string($v) || $v === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }

    public function pdf(RecuVersement $recu): string
    {
        $recu->loadMissing('annulation');

        return Pdf::loadView('pdf.recu-versement', ['recu' => $recu, 'lettres' => $this->enLettres($recu->montant)])->setPaper('a5', 'landscape')->output();
    }

    public function pdfAnnulation(AnnulationRecu $a): string
    {
        $a->loadMissing('recu');

        return Pdf::loadView('pdf.annulation-recu', ['annulation' => $a, 'recu' => $a->recu, 'lettres' => $this->enLettres($a->recu->montant)])->setPaper('a5', 'landscape')->output();
    }

    /** Montant en toutes lettres : « cent quatre-vingt-cinq mille francs CFA ». */
    public function enLettres(int $montant): string
    {
        return (new NumberFormatter('fr', NumberFormatter::SPELLOUT))->format($montant).' francs CFA';
    }

    /**
     * Imputation du versement sur les paliers de l'année (de l'inscription vers la dernière tranche), après les versements
     * valides qui le précèdent (date, puis ordre d'enregistrement).
     *
     * @return array{objet: string, total_verse: int, reste: int}
     */
    public function imputation(InscriptionAnnuelle $inscription, Versement $v): array
    {
        $precedents = (int) $inscription->versementsValides()
            ->where(fn ($q) => $q->where('date_versement', '<', $v->date_versement->toDateString())
                ->orWhere(fn ($x) => $x->where('date_versement', $v->date_versement->toDateString())->where('id', '<', $v->id)))
            ->sum('montant');
        $s = SituationFinanciere::calculer($inscription->montant_inscription, $inscription->montant_tranche1, $inscription->montant_tranche2,
            (int) $inscription->remise_montant, 0, InscriptionAnnuelle::aujourdhui());
        $debut = $precedents;
        $fin = $precedents + $v->montant;
        $parts = [];
        $borne = 0;
        foreach ($s->paliers as $p) {
            $part = max(0, min($fin, $borne + $p->du) - max($debut, $borne));
            if ($part > 0) {
                $parts[] = self::LIBELLES_PALIERS[$p->cle].' ('.self::fcfa($part).')';
            }
            $borne += $p->du;
        }
        $exces = max(0, $fin - max($debut, $borne));
        if ($exces > 0) {
            $parts[] = 'trop-perçu ('.self::fcfa($exces).')';
        }
        $objet = 'Frais de scolarité '.$inscription->annee_academique.' : '.implode(', ', $parts === [] ? ['versement'] : $parts);

        return ['objet' => mb_substr($objet, 0, 255), 'total_verse' => $fin, 'reste' => max(0, $s->duTotal - $fin)];
    }

    public static function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', ' ').' FCFA';
    }

    private function ecrireAnnulation(RecuVersement $recu, string $motif, User $auteur): AnnulationRecu
    {
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new InvalidArgumentException('L\'annulation d\'un reçu exige un motif.');
        }

        return DB::transaction(function () use ($recu, $motif, $auteur): AnnulationRecu {
            RecuVersement::query()->whereKey($recu->id)->lockForUpdate()->firstOrFail();
            if (AnnulationRecu::query()->where('recu_id', $recu->id)->exists()) {
                throw new InvalidArgumentException('Ce reçu est déjà annulé.');
            }
            $a = (new AnnulationRecu)->forceFill([
                'recu_id' => $recu->id,
                'numero' => $recu->numero.'-A',
                'motif' => mb_substr($motif, 0, 500),
                'annule_par' => $auteur->id,
                'annuleur_nom' => $auteur->name,
                'annule_at' => now(),
            ]);
            $a->save();
            activity('scolarite')->causedBy($auteur)->performedOn($recu->versement)->event('recu_annule')
                ->withProperties(['numero' => $recu->numero, 'ecriture' => $a->numero, 'motif' => $motif])->log('Reçu annulé');
            $recu->unsetRelation('annulation');

            return $a;
        });
    }

    /** Numéro suivant de l'année, sous verrou du compteur. */
    private function prochainNumero(int $annee): int
    {
        DB::table('compteurs_recus')->insertOrIgnore(['annee' => $annee, 'dernier' => 0]);
        $dernier = (int) DB::table('compteurs_recus')->where('annee', $annee)->lockForUpdate()->value('dernier');
        DB::table('compteurs_recus')->where('annee', $annee)->update(['dernier' => $dernier + 1]);

        return $dernier + 1;
    }
}
