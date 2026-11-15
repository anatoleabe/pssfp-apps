<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\AnnulationRecu;
use App\Models\ConfirmationVersement;
use App\Models\ImputationVersement;
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
 * Depuis le compte de l'auditeur (ADR-0013), l'objet se lit dans les imputations du versement et
 * l'excédent y est libellé « avance sur la scolarité à venir ».
 */
final class RecusVersements
{
    public const PERMISSION_EMETTRE = 'scolarite.recu_emettre';

    /** Versements enregistrés à partir de cette date (mise en service) : les antérieurs n'ont pas de reçu. */
    public const CLE_DEPUIS = 'scolarite.recus_depuis';

    /**
     * Arrêt d'ACMA (décision du 15/11/2026), posé par `scolarite:bascule-recus` : la liste d'attente part
     * de cette date ; les versements antérieurs ont un reçu ACMA et n'en reçoivent un qu'à la demande.
     */
    public const CLE_BASCULE = 'scolarite.recus_bascule';

    private const LIBELLES_PALIERS = [Palier::INSCRIPTION => 'droits d\'inscription', Palier::TRANCHE1 => '1re tranche', Palier::TRANCHE2 => '2e tranche'];

    public const LIBELLE_AVANCE = 'avance sur la scolarité à venir';

    /** Longueur de la colonne `recus_versements.objet` (spec D1, §5.5). */
    public const OBJET_MAX = 500;

    public function __construct(
        private readonly ImputationsCompte $imputations,
        private readonly AvisRecus $avis,
    ) {}

    /**
     * Le versement peut-il recevoir un reçu (hors permission) ? null si oui, sinon la raison. Depuis le
     * 15/11/2026, l'agent qui a saisi le versement en émet le reçu (le contrôle passe à la scolarité) :
     * `$auteur` ne sert plus qu'à la signature, gardée pour les appelants.
     */
    public function raisonRefus(Versement $v, ?User $auteur = null): ?string
    {
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
        if ($v->auditeur?->candidature?->withdrawn_at !== null) {
            return 'Le dossier de cet auditeur est retiré : il ne reçoit plus de reçu.';
        }
        if ($v->recus()->whereDoesntHave('annulation')->exists()) {
            return 'Ce versement a déjà un reçu en vigueur.';
        }

        return null;
    }

    /**
     * Versements qui attendent leur reçu : la traduction en requête de
     * raisonRefus() (pastille du menu, tableau de bord), bornée par
     * debutAttente(). Vide tant que la mise en service n'est pas réglée.
     * `$agent` reste dans la signature des écrans : depuis le 15/11/2026, la
     * liste ne dépend plus de qui a saisi le versement.
     *
     * @return Builder<Versement>
     */
    public function enAttente(User $agent): Builder
    {
        $debut = $this->debutAttente();

        return Versement::query()
            ->when($debut === null, fn (Builder $q) => $q->whereIn('id', []))
            ->when($debut !== null, fn (Builder $q) => $q->where('created_at', '>=', $debut))
            ->whereNull('annule_at')
            ->whereDoesntHave('recus', fn (Builder $q) => $q->whereDoesntHave('annulation'))
            ->whereDoesntHave('auditeur.candidature', fn (Builder $q) => $q->whereNotNull('withdrawn_at'));
    }

    public function emettre(Versement $versement, User $auteur): RecuVersement
    {
        abort_unless($auteur->can(self::PERMISSION_EMETTRE), 403);

        // Verrou de l'auditeur (ADR-0013, R8) : il garantit l'invariant « un seul reçu en vigueur par versement », que la
        // base ne peut pas exprimer (l'annulation vit dans une autre table), et fige les imputations lues. Toute création
        // de reçu passe par ici.
        return $this->imputations->sousVerrou((int) $versement->auditeur_id, function () use ($versement, $auteur): RecuVersement {
            $v = Versement::query()->with('auditeur.candidature')->whereKey($versement->id)->firstOrFail();
            $raison = $this->raisonRefus($v, $auteur);
            if ($raison !== null) {
                throw new InvalidArgumentException($raison);
            }
            $inscription = InscriptionAnnuelle::query()->with('bareme')->whereKey($v->inscription_annuelle_id)->firstOrFail();
            $calcul = $this->imputation($inscription, $v);
            $annee = (int) now('Africa/Douala')->format('Y');
            $sequence = $this->prochainNumero($annee);
            $dossier = $v->auditeur?->candidature;
            // Vérification par la scolarité, figée sur le reçu si elle précède l'émission (réémission après annulation).
            $verification = ConfirmationVersement::query()->with('confirmePar:id,name')->where('versement_id', $v->id)->first();
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
                'verifie_par_nom' => $verification?->confirmePar?->name,
                'verifie_le' => $verification?->confirme_at?->setTimezone(InscriptionAnnuelle::FUSEAU)->toDateString(),
            ]);
            $recu->save();
            activity('scolarite')->causedBy($auteur)->performedOn($v)->event('recu_emis')
                ->withProperties(['numero' => $recu->numero, 'montant' => $recu->montant])->log('Reçu émis');
            if ($dossier !== null) {
                // Après la validation de la transaction : jamais d'avis pour un reçu défait (spec §5).
                $this->avis->apresEmission($recu, $dossier, $auteur);
            }

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
        return self::dateReglage(self::CLE_DEPUIS);
    }

    /** Date de bascule ACMA ; null tant que `scolarite:bascule-recus` ne l'a pas posée. */
    public function bascule(): ?CarbonImmutable
    {
        return self::dateReglage(self::CLE_BASCULE);
    }

    /**
     * Début de la liste d'attente : la plus tardive de la mise en service et de la bascule.
     * Sans bascule, la mise en service (comportement d'avant le 15/11/2026) ; sans mise en service, null.
     */
    public function debutAttente(): ?CarbonImmutable
    {
        $depuis = $this->miseEnService();
        $bascule = $this->bascule();

        return $depuis === null || $bascule === null || $bascule->lessThan($depuis) ? $depuis : $bascule;
    }

    /** Jour du début de la liste d'attente, JJ/MM/AAAA à l'heure de Yaoundé ; null sans mise en service. */
    public function jourDebutAttente(): ?string
    {
        return $this->debutAttente()?->setTimezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y');
    }

    /**
     * Date d'un réglage, ramenée au fuseau de l'application : une requête écrit la date sans
     * son fuseau, si bien qu'une date gardée à l'heure de Yaoundé décalerait la borne.
     */
    private static function dateReglage(string $cle): ?CarbonImmutable
    {
        $v = AppSettings::get($cle);
        if (! is_string($v) || $v === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($v)->setTimezone((string) config('app.timezone'));
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
     * Objet, total versé pour l'année et reste du reçu, lus dans les imputations actives du versement
     * (spec D1, §9.2). Pour chaque inscription touchée, la part du versement se découpe sur les paliers
     * (de l'inscription vers la dernière tranche) à partir de l'imputé des versements enregistrés avant
     * lui : c'est l'ordre dans lequel R2 les a imputés, si bien qu'un versement antidaté ne contredit pas
     * la situation. Ce qui n'est imputé nulle part est l'avance. « Total versé pour l'année » porte sur
     * l'inscription désignée, avance exclue.
     *
     * @return array{objet: string, total_verse: int, reste: int}
     */
    public function imputation(InscriptionAnnuelle $designee, Versement $v): array
    {
        $parts = ImputationVersement::query()->where('versement_id', $v->id)->whereNull('annule_at')
            ->groupBy('inscription_annuelle_id')->selectRaw('inscription_annuelle_id, SUM(montant) AS part')
            ->pluck('part', 'inscription_annuelle_id')->map(fn ($m): int => (int) $m);
        $touchees = InscriptionAnnuelle::query()->whereKey($parts->keys()->all())->orderBy('annee_academique')->get();
        $avance = $v->montant - (int) $parts->sum();
        $libelleAvance = $avance > 0 ? self::LIBELLE_AVANCE.' ('.self::fcfa($avance).')' : null;

        $detail = [];
        $resume = [];
        foreach ($touchees as $i) {
            $avant = $this->imputeAvant($i, $v);
            $paliers = $this->parts($i, $avant, $avant + $parts[$i->id]);
            $detail[] = $i->annee_academique.' ('.$i->niveau.') : '.implode(', ', $paliers);
            $resume[] = $i->annee_academique.' ('.$i->niveau.') : '.self::fcfa($parts[$i->id]);
        }

        if ($touchees->every(fn (InscriptionAnnuelle $i): bool => $i->id === $designee->id)) {
            // Format du lot 4, conservé pour une seule année.
            $avant = $this->imputeAvant($designee, $v);
            $morceaux = [...$this->parts($designee, $avant, $avant + $parts->get($designee->id, 0)), ...array_filter([$libelleAvance])];
            $objet = 'Frais de scolarité '.$designee->annee_academique.' : '.implode(', ', $morceaux === [] ? ['versement'] : $morceaux);
        } else {
            $objet = 'Frais de scolarité '.implode(' ; ', [...$detail, ...array_filter([$libelleAvance])]);
            if (mb_strlen($objet) > self::OBJET_MAX) {
                $objet = 'Frais de scolarité '.implode(' ; ', [...$resume, ...array_filter([$libelleAvance])]);
            }
        }

        $total = $this->imputeAvant($designee, $v) + $parts->get($designee->id, 0);
        $du = SituationFinanciere::calculer($designee->montant_inscription, $designee->montant_tranche1, $designee->montant_tranche2,
            (int) $designee->remise_montant, 0, InscriptionAnnuelle::aujourdhui())->duTotal;

        return ['objet' => mb_substr($objet, 0, self::OBJET_MAX), 'total_verse' => $total, 'reste' => max(0, $du - $total)];
    }

    /** Imputé actif sur l'inscription par les versements enregistrés avant `$v` (ordre d'imputation de R2). */
    private function imputeAvant(InscriptionAnnuelle $inscription, Versement $v): int
    {
        return (int) ImputationVersement::query()
            ->where('inscription_annuelle_id', $inscription->id)
            ->whereNull('annule_at')
            ->where('versement_id', '<', $v->id)
            ->sum('montant');
    }

    /**
     * Libellés des paliers couverts entre `$debut` et `$fin` du cumul de l'inscription.
     *
     * @return list<string>
     */
    private function parts(InscriptionAnnuelle $inscription, int $debut, int $fin): array
    {
        $s = SituationFinanciere::calculer($inscription->montant_inscription, $inscription->montant_tranche1, $inscription->montant_tranche2,
            (int) $inscription->remise_montant, 0, InscriptionAnnuelle::aujourdhui());
        $parts = [];
        $borne = 0;
        foreach ($s->paliers as $p) {
            $part = max(0, min($fin, $borne + $p->du) - max($debut, $borne));
            if ($part > 0) {
                $parts[] = self::LIBELLES_PALIERS[$p->cle].' ('.self::fcfa($part).')';
            }
            $borne += $p->du;
        }

        return $parts;
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
