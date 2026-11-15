<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\DeblocageAcces;
use App\Models\EngagementPriseEnCharge;
use App\Models\InscriptionAnnuelle;
use App\Models\ReglementOrganisme;
use App\Models\User;
use App\Models\Versement;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Règlements des organismes (spec module 4, lot A2 §9.7) : l'argent reçu sur un engagement,
 * réparti en un versement par auditeur couvert (payeur employeur), et son annulation d'un bloc.
 * Seul point d'écriture de `reglements_organismes` ; les versements passent par
 * RegistreVersements, leurs imputations par le compte de l'auditeur (D1).
 *
 * Verrous, toujours dans cet ordre : l'engagement (ou le règlement), puis chaque auditeur par
 * `id` croissant, avant toute écriture ; puis, pour chaque versement, le verrou déjà tenu de son
 * auditeur (R8 de D1). Tout se fait dans une transaction : une part refusée annule tout.
 */
final class ReglementsOrganismes
{
    public const MONTANT_MAX = 1_000_000_000;

    public const REFERENCE_MAX = 60;

    public const MOTIF_MIN = 5;

    public const MOTIF_MAX = 500;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RegistreVersements $registre,
    ) {}

    public static function peutEnregistrer(?User $u): bool
    {
        return (bool) $u?->can(RegistreVersements::PERMISSION_SAISIR);
    }

    public static function peutAnnuler(?User $u): bool
    {
        return (bool) $u?->can(RegistreVersements::PERMISSION_ANNULER);
    }

    /**
     * Inscriptions couvertes qui peuvent recevoir une part (dossier non retiré), par id d'inscription.
     *
     * @return array<string, array{matricule: string, nom: string, couvert: int, reste: int}> uuid de l'inscription => ligne
     */
    public static function couverts(EngagementPriseEnCharge $engagement): array
    {
        $suivi = SuiviEngagement::de(EngagementPriseEnCharge::query()->avecSuivi()->findOrFail($engagement->id));
        $auditeurs = Auditeur::query()->with('candidature:id,nom,prenom,matricule,withdrawn_at')
            ->whereKey(array_map(fn (array $l): int => (int) $l['inscription']->auditeur_id, $suivi->lignes))->get()->keyBy('id');

        $lignes = [];
        foreach ($suivi->lignes as $l) {
            $c = $auditeurs->get($l['inscription']->auditeur_id)?->candidature;
            if ($c === null || $c->withdrawn_at !== null) {
                continue;
            }
            $lignes[(string) $l['inscription']->uuid] = [
                'matricule' => (string) $c->matricule,
                'nom' => trim(mb_strtoupper((string) $c->nom).' '.$c->prenom),
                'couvert' => $l['couvert'],
                'reste' => $l['reste'],
            ];
        }

        return $lignes;
    }

    /**
     * Parts proposées : le reste dû dans la portée de l'engagement, pour chaque auditeur couvert.
     *
     * @return array<string, int> uuid de l'inscription => montant
     */
    public static function partsProposees(EngagementPriseEnCharge $engagement): array
    {
        return array_map(fn (array $l): int => $l['reste'], self::couverts($engagement));
    }

    /**
     * Parts au-delà du reste couvert, pour la case de confirmation du formulaire.
     *
     * @param  array<string, mixed>  $parts
     * @return array<string, int> matricule => excédent
     */
    public static function depassements(EngagementPriseEnCharge $engagement, array $parts): array
    {
        $excedents = [];
        foreach (self::couverts($engagement) as $uuid => $l) {
            $part = filter_var($parts[$uuid] ?? 0, FILTER_VALIDATE_INT);
            if ($part !== false && $part > $l['reste']) {
                $excedents[$l['matricule']] = $part - $l['reste'];
            }
        }

        return $excedents;
    }

    /**
     * @param  array{montant?: mixed, date_reglement?: mixed, mode?: mixed, reference?: mixed, piece_path?: mixed, parts?: mixed, confirmer_depassement?: mixed}  $saisie
     *
     * @throws InvalidArgumentException
     */
    public function enregistrer(EngagementPriseEnCharge $engagement, array $saisie, User $auteur): ResultatReglement
    {
        abort_unless(self::peutEnregistrer($auteur), 403);

        $montant = filter_var($saisie['montant'] ?? null, FILTER_VALIDATE_INT);
        if ($montant === false || $montant <= 0) {
            throw new InvalidArgumentException('Le montant doit être un nombre entier de FCFA, supérieur à zéro.');
        }
        if ($montant > self::MONTANT_MAX) {
            throw new InvalidArgumentException('Le montant ne peut pas dépasser '.RecusVersements::fcfa(self::MONTANT_MAX).' : vérifiez la saisie.');
        }
        $date = self::date($saisie['date_reglement'] ?? null);
        $mode = (string) ($saisie['mode'] ?? '');
        if (! array_key_exists($mode, Versement::MODES)) {
            throw new InvalidArgumentException('Choisissez un mode de paiement de la liste.');
        }
        $reference = Versement::normaliserReference((string) ($saisie['reference'] ?? ''));
        if ($reference === '' || mb_strlen($reference) > self::REFERENCE_MAX) {
            throw new InvalidArgumentException('Indiquez la référence du règlement ('.self::REFERENCE_MAX.' caractères au plus).');
        }
        $piece = self::piece($saisie['piece_path'] ?? null);
        $parts = self::parts($saisie['parts'] ?? []);
        if (array_sum($parts) !== $montant) {
            throw new InvalidArgumentException('La somme des parts ('.RecusVersements::fcfa(array_sum($parts)).') doit égaler le montant du règlement ('.RecusVersements::fcfa($montant).').');
        }
        $confirme = filter_var($saisie['confirmer_depassement'] ?? false, FILTER_VALIDATE_BOOL);

        try {
            [$reglement, $inscriptions] = $this->db->transaction(
                fn (): array => $this->ecrire($engagement, $montant, $date, $mode, $reference, $piece, $parts, $confirme, $auteur),
            );
        } catch (UniqueConstraintViolationException) {
            // Deux agents sur le même règlement, au même moment : l'index tranche.
            throw new InvalidArgumentException(self::messageDoublon($reference));
        }

        // Après la validation : régularisation des accès anticipés et messages, sans jamais défaire le règlement.
        $prevenus = 0;
        $echecs = 0;
        foreach ($inscriptions as $inscription) {
            $notification = $this->registre->apresReglement($inscription, $auteur);
            $prevenus += $notification === true ? 1 : 0;
            $echecs += $notification === false ? 1 : 0;
        }

        return new ResultatReglement($reglement, count($inscriptions), $prevenus, $echecs);
    }

    /**
     * Annule le règlement et tous ses versements, d'un bloc (responsable). Si l'un des versements
     * est imputé sur plusieurs années (R4.1 de D1), rien n'est annulé.
     *
     * @throws InvalidArgumentException
     */
    public function annuler(ReglementOrganisme $reglement, mixed $motif, User $auteur): void
    {
        abort_unless(self::peutAnnuler($auteur), 403);
        $motif = trim((string) $motif);
        if (mb_strlen($motif) < self::MOTIF_MIN) {
            throw new InvalidArgumentException('Indiquez le motif de l\'annulation (au moins '.self::MOTIF_MIN.' caractères).');
        }
        $motif = mb_substr($motif, 0, self::MOTIF_MAX);

        $this->db->transaction(function () use ($reglement, $motif, $auteur): void {
            $r = ReglementOrganisme::query()->whereKey($reglement->id)->lockForUpdate()->firstOrFail();
            if ($r->estAnnule()) {
                throw new InvalidArgumentException('Ce règlement est déjà annulé.');
            }
            $versements = Versement::query()->where('reglement_organisme_id', $r->id)->whereNull('annule_at')
                ->with('auditeur.candidature:id,matricule')->orderBy('auditeur_id')->orderBy('id')->get();
            self::verrouiller($versements->pluck('auditeur_id')->all());

            // Refus R4.1, annoncé pour tout le règlement avant la moindre écriture.
            foreach ($versements as $v) {
                $annees = RegistreVersements::anneesTouchees($v);
                if (count($annees) > 1) {
                    throw new InvalidArgumentException(self::messageMultiAnnees($v, $annees));
                }
            }
            $libelle = 'Règlement '.$r->reference.' annulé : '.$motif;
            foreach ($versements as $v) {
                try {
                    $this->registre->annulerPartReglement($v, $libelle, $auteur);
                } catch (AnnulationMultiAnnees $e) {
                    throw new InvalidArgumentException(self::messageMultiAnnees($v, $e->annees));
                }
            }

            $r->forceFill(['annule_at' => now(), 'annule_par' => $auteur->id, 'annule_motif' => $motif])->save();
            activity('scolarite')->causedBy($auteur)->performedOn($r)
                ->event('reglement_annule')
                ->withProperties(['motif' => $motif, 'montant' => $r->montant, 'reference' => $r->reference, 'versements' => $versements->count()])
                ->log('Règlement d\'organisme annulé');
        });
    }

    /**
     * Sous le verrou de l'engagement puis des auditeurs : contrôles, règlement, un versement par part.
     *
     * @param  array<string, int>  $parts
     * @return array{0: ReglementOrganisme, 1: list<InscriptionAnnuelle>}
     */
    private function ecrire(EngagementPriseEnCharge $engagement, int $montant, CarbonImmutable $date, string $mode, string $reference,
        ?string $piece, array $parts, bool $confirme, User $auteur): array
    {
        $e = EngagementPriseEnCharge::query()->whereKey($engagement->id)->lockForUpdate()->firstOrFail();
        $couvertes = InscriptionAnnuelle::query()
            ->whereIn('id', DeblocageAcces::query()->where('engagement_id', $e->id)->where('statut', '<>', DeblocageAcces::REVOQUE)->select('inscription_annuelle_id'))
            ->with('auditeur.candidature:id,matricule,withdrawn_at')->get()->keyBy('uuid');

        // Parts : inscriptions couvertes, dossiers ouverts, rangées par id d'auditeur croissant.
        $lignes = [];
        foreach ($parts as $uuid => $part) {
            $inscription = $couvertes->get($uuid) ?? throw new InvalidArgumentException('Une part porte sur un auditeur qui n\'est pas couvert par cet engagement : rechargez le formulaire.');
            $c = $inscription->auditeur?->candidature;
            if ($c === null || $c->withdrawn_at !== null) {
                throw new InvalidArgumentException('Le dossier de '.($c?->matricule ?? 'cet auditeur').' est retiré : aucune part ne peut lui être versée.');
            }
            if (blank($c->matricule)) {
                throw new InvalidArgumentException('Un auditeur couvert n\'a pas de matricule : sa part ne peut pas être référencée.');
            }
            $lignes[] = ['inscription' => $inscription, 'matricule' => (string) $c->matricule, 'part' => $part, 'reference' => $reference.'-'.$c->matricule];
        }
        usort($lignes, fn (array $a, array $b): int => [$a['inscription']->auditeur_id, $a['inscription']->id] <=> [$b['inscription']->auditeur_id, $b['inscription']->id]);

        $this->refuserDoublons($mode, $reference, $lignes);
        self::verrouiller(array_map(fn (array $l): int => (int) $l['inscription']->auditeur_id, $lignes));

        // Sous les verrous, les restes ne bougent plus : un dépassement exige la confirmation.
        $fraiches = InscriptionAnnuelle::query()->with('bareme')->whereKey(array_map(fn (array $l): int => $l['inscription']->id, $lignes))->get()->keyBy('id');
        $depassements = [];
        foreach ($lignes as $l) {
            [, $reste] = SuiviEngagement::portee($e->portee, $fraiches->get($l['inscription']->id));
            if ($l['part'] > $reste) {
                $depassements[] = $l['matricule'].' ('.RecusVersements::fcfa($l['part'] - $reste).' de plus)';
            }
        }
        if ($depassements !== [] && ! $confirme) {
            throw new InvalidArgumentException('Confirmez le dépassement : la part de '.implode(', ', $depassements).' dépasse le reste couvert par l\'engagement. '
                .'L\'excédent ira sur le reste de l\'année, puis restera en avance réservée, en attente de la décision du responsable.');
        }

        $r = new ReglementOrganisme;
        $r->forceFill([
            'engagement_id' => $e->id,
            'montant' => $montant,
            'date_reglement' => $date->toDateString(),
            'mode' => $mode,
            'reference' => $reference,
            'piece_path' => $piece,
            'saisi_par' => $auteur->id,
        ])->save();

        $inscriptions = [];
        foreach ($lignes as $l) {
            $this->registre->enregistrerPartReglement($r, $l['inscription'], $l['part'], $l['reference'], $auteur);
            $inscriptions[] = $l['inscription'];
        }

        activity('scolarite')->causedBy($auteur)->performedOn($r)
            ->event('reglement_enregistre')
            ->withProperties(['montant' => $montant, 'mode' => $mode, 'reference' => $reference, 'engagement' => $e->uuid,
                'parts' => array_map(fn (array $l): array => ['inscription' => (string) $l['inscription']->uuid, 'montant' => $l['part']], $lignes)])
            ->log('Règlement d\'organisme enregistré ('.$e->organisme.')');

        return [$r, $inscriptions];
    }

    /**
     * Un règlement ne se saisit qu'une fois, et l'argent d'un organisme jamais deux fois : ni un
     * autre règlement, ni un versement de même référence, ni une part déjà saisie.
     *
     * @param  list<array{matricule: string, reference: string}>  $lignes
     */
    private function refuserDoublons(string $mode, string $reference, array $lignes): void
    {
        if (ReglementOrganisme::query()->where('mode', $mode)->where('reference', $reference)->whereNull('annule_at')->exists()) {
            throw new InvalidArgumentException(self::messageDoublon($reference));
        }
        $versement = Versement::query()->with('auditeur.candidature:id,matricule')->where('mode', $mode)->where('reference', $reference)->whereNull('annule_at')->first();
        if ($versement !== null) {
            throw new InvalidArgumentException("La référence {$reference} a déjà été saisie comme versement dans ce mode de paiement (fiche de "
                .($versement->auditeur?->candidature?->matricule ?? '—').') : l\'argent ne se compte pas deux fois. Faites d\'abord annuler ce versement, ou vérifiez le bordereau.');
        }
        $trop = array_values(array_filter($lignes, fn (array $l): bool => mb_strlen($l['reference']) > 100));
        if ($trop !== []) {
            throw new InvalidArgumentException('La référence '.$trop[0]['reference'].' dépasse 100 caractères : raccourcissez la référence du règlement.');
        }
        $existantes = Versement::query()->where('mode', $mode)->whereNull('annule_at')
            ->whereIn('reference', array_column($lignes, 'reference'))->pluck('reference')->all();
        if ($existantes !== []) {
            throw new InvalidArgumentException('Ces références existent déjà comme versements dans ce mode de paiement : '.implode(', ', $existantes).'. Vérifiez le bordereau.');
        }
    }

    /**
     * Verrou de chaque auditeur, par id croissant (R8 de D1) : deux règlements qui partagent des
     * auditeurs se suivent sans interblocage.
     *
     * @param  list<int|string>  $ids
     */
    private static function verrouiller(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        foreach ($ids as $id) {
            Auditeur::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        }
    }

    /** @return array<string, int> uuid de l'inscription => part positive */
    private static function parts(mixed $saisie): array
    {
        if (! is_array($saisie)) {
            throw new InvalidArgumentException('Indiquez la part de chaque auditeur.');
        }
        $parts = [];
        foreach ($saisie as $uuid => $valeur) {
            $part = blank($valeur) ? 0 : filter_var($valeur, FILTER_VALIDATE_INT);
            if ($part === false || $part < 0) {
                throw new InvalidArgumentException('Chaque part est un nombre entier positif ou nul de FCFA.');
            }
            if ($part > RegistreVersements::MONTANT_MAX) {
                throw new InvalidArgumentException('Une part ne peut pas dépasser '.RecusVersements::fcfa(RegistreVersements::MONTANT_MAX).'.');
            }
            if ($part > 0) {
                $parts[(string) $uuid] = $part;
            }
        }
        if ($parts === []) {
            throw new InvalidArgumentException('Indiquez au moins une part positive.');
        }

        return $parts;
    }

    private static function date(mixed $saisie): CarbonImmutable
    {
        if (blank($saisie)) {
            throw new InvalidArgumentException('Indiquez la date du règlement.');
        }
        try {
            $date = CarbonImmutable::parse((string) $saisie, InscriptionAnnuelle::FUSEAU)->startOfDay();
        } catch (InvalidFormatException) {
            throw new InvalidArgumentException('Date invalide : choisissez-la dans le calendrier.');
        }
        if ($date->greaterThan(InscriptionAnnuelle::aujourdhui())) {
            throw new InvalidArgumentException('La date du règlement ne peut pas être dans le futur.');
        }

        return $date;
    }

    /** Pièce facultative, déposée par le formulaire, propre à ce règlement. */
    private static function piece(mixed $chemin): ?string
    {
        if (blank($chemin)) {
            return null;
        }
        $chemin = (string) $chemin;
        if (! Versement::cheminJustificatifValide($chemin)) {
            throw new InvalidArgumentException('La pièce jointe est invalide : déposez-la à nouveau.');
        }
        if (Versement::pieceDejaJointe($chemin)) {
            throw new InvalidArgumentException('Cette pièce est déjà jointe à un versement, à un accès anticipé, à un engagement ou à un règlement : déposez la pièce propre à ce règlement.');
        }

        return $chemin;
    }

    /** @param  list<string>  $annees */
    private static function messageMultiAnnees(Versement $v, array $annees): string
    {
        return 'Le règlement ne peut pas être annulé : le versement de '.($v->auditeur?->candidature?->matricule ?? '—').' est imputé sur plusieurs années ('
            .implode(', ', $annees).') ; son annulation passera par une demande d\'autorisation (sous-lot D4). Rien n\'a été annulé. Prévenez l\'USI.';
    }

    private static function messageDoublon(string $reference): string
    {
        return "La référence {$reference} a déjà été saisie pour un règlement dans ce mode de paiement. Vérifiez le bordereau, ou faites d'abord annuler le règlement erroné.";
    }
}
