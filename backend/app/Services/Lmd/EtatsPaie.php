<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\BaremeRemuneration;
use App\Models\DossierPaieEnseignant;
use App\Models\EtatPaie;
use App\Models\LigneEtatPaie;
use App\Models\PointageEnseignant;
use App\Models\ProgrammationEnseignant;
use App\Models\User;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * États de paie mensuels des enseignants (spec lot 13b-1 ; D23, D24 ; port de generer_etat_paie, avancer_etat_paie,
 * rejeter_etat_paie). Une heure n'est payée qu'une fois, si elle est validée et comprise dans l'allocation.
 */
final class EtatsPaie
{
    public const CLE_VISA = 'lmd.visa_controleur_paie';

    private const COURS = ['CM', 'TD', 'TP'];

    /** Étape du circuit → [statuts de départ admis, permission]. Le visa ne s'insère que si le paramètre est actif. */
    private const ETAPES = [
        EtatPaie::VERIFIE => [[EtatPaie::BROUILLON], PermissionsLmd::PAIE_VERIFIER],
        EtatPaie::VISE => [[EtatPaie::VERIFIE], PermissionsLmd::PAIE_VISER],
        EtatPaie::TRANSMIS => [[EtatPaie::VERIFIE, EtatPaie::VISE], PermissionsLmd::PAIE_VERIFIER],
        EtatPaie::PRIS_EN_CHARGE => [[EtatPaie::TRANSMIS], PermissionsLmd::PAIE_PAYER],
        EtatPaie::PAYE => [[EtatPaie::PRIS_EN_CHARGE], PermissionsLmd::PAIE_PAYER],
    ];

    // ---------------------------------------------------------------- création et génération

    /** Crée et génère l'état ordinaire d'un mois (AAAA-MM) ; un seul par mois, hors états rejetés. */
    public function creerMensuel(string $mois, User $auteur): EtatPaie
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_PREPARER), 403);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $mois) !== 1) {
            throw new InvalidArgumentException('Le mois est au format AAAA-MM.');
        }
        $debut = CarbonImmutable::parse($mois.'-01');
        if ($debut->format('Y-m') > now('Africa/Douala')->format('Y-m')) {
            throw new InvalidArgumentException('L\'état d\'un mois futur ne se prépare pas.');
        }

        return DB::transaction(function () use ($debut, $auteur): EtatPaie {
            if (EtatPaie::query()->where('periode_du', $debut->toDateString())->whereNull('rectificatif_de')->where('statut', '<>', EtatPaie::REJETE)->exists()) {
                throw new InvalidArgumentException('L\'état de '.$debut->format('m/Y').' existe déjà : un seul état ordinaire par mois (rectificatif ensuite).');
            }
            $base = 'EP-'.$debut->format('Y-m');
            $deja = EtatPaie::query()->where('periode_du', $debut->toDateString())->whereNull('rectificatif_de')->count();
            $etat = (new EtatPaie)->forceFill([
                'numero' => $deja === 0 ? $base : $base.'-'.($deja + 1),
                'periode_du' => $debut->toDateString(),
                'periode_au' => $debut->endOfMonth()->toDateString(),
                'objet' => 'État de paie des enseignants : '.$debut->format('m/Y'),
                'cree_par' => $auteur->id,
            ]);
            $etat->save();
            $this->generer($etat);

            return $etat->refresh();
        });
    }

    /** État rectificatif d'un état pris en charge ou payé : il reprend les pointages validés restants du mois. */
    public function creerRectificatif(EtatPaie $origine, User $auteur): EtatPaie
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_PREPARER), 403);
        if (! in_array($origine->statut, [EtatPaie::PRIS_EN_CHARGE, EtatPaie::PAYE], true) || $origine->rectificatif_de !== null) {
            throw new InvalidArgumentException('Un rectificatif se prépare sur un état ordinaire pris en charge ou payé.');
        }

        return DB::transaction(function () use ($origine, $auteur): EtatPaie {
            $n = $origine->rectificatifs()->count() + 1;
            $etat = (new EtatPaie)->forceFill([
                'numero' => $origine->numero.'-R'.$n,
                'periode_du' => $origine->periode_du->toDateString(),
                'periode_au' => $origine->periode_au->toDateString(),
                'objet' => 'État rectificatif n° '.$n.' de l\'état '.$origine->numero,
                'rectificatif_de' => $origine->id,
                'cree_par' => $auteur->id,
            ]);
            $etat->save();
            $this->generer($etat);

            return $etat->refresh();
        });
    }

    /** Régénère un brouillon : ses lignes sont retirées, ses pointages libérés, puis il est recalculé. */
    public function regenerer(EtatPaie $etat, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_PREPARER), 403);
        DB::transaction(function () use ($etat): void {
            $e = EtatPaie::query()->whereKey($etat->id)->lockForUpdate()->firstOrFail();
            if ($e->statut !== EtatPaie::BROUILLON) {
                throw new InvalidArgumentException('Seul un état en brouillon se régénère.');
            }
            $this->liberer($e);
            $e->lignes()->delete();
            $this->generer($e);
        });
    }

    /**
     * Génère les lignes d'un état en brouillon. Retourne le nombre de lignes.
     */
    public function generer(EtatPaie $etat): int
    {
        if ($etat->statut !== EtatPaie::BROUILLON) {
            throw new InvalidArgumentException('Seul un état en brouillon se génère.');
        }
        $pointages = PointageEnseignant::query()->with(['enseignant', 'seance.ecue.maquetteUe.maquette'])
            ->where('statut', PointageEnseignant::VALIDE)->whereNull('ligne_paie_id')
            ->whereHas('seance', fn ($q) => $q->whereBetween('date_seance', [$etat->periode_du->toDateString(), $etat->periode_au->toDateString()]))
            ->orderBy('id')->get();
        $dossiers = DossierPaieEnseignant::query()->whereIn('enseignant_id', $pointages->pluck('enseignant_id')->unique())->get()->keyBy('enseignant_id');
        $baremes = BaremeRemuneration::query()->where('statut', 'valide')->get()->keyBy(fn (BaremeRemuneration $b): string => $b->annee_academique.'|'.$b->nature);
        $restantService = [];
        $ecartes = [];
        $n = 0;

        foreach ($pointages->groupBy(fn (PointageEnseignant $p): string => $p->enseignant_id.'|'.$p->seance->maquette_ecue_id.'|'.$p->nature) as $groupe) {
            /** @var PointageEnseignant $premier */
            $premier = $groupe->first();
            $enseignant = $premier->enseignant;
            $ecue = $premier->seance->ecue;
            $annee = (string) $ecue->maquetteUe->maquette->annee_academique;
            $nature = $premier->nature;
            $dossier = $dossiers->get($enseignant->id);
            $raison = match (true) {
                $dossier === null => 'pas de dossier de paie',
                ! $dossier->actif => 'dossier de paie inactif',
                ! $dossier->estComplet() => implode(' et ', $dossier->manques()).' manquant(s)',
                default => null,
            };
            $bareme = $baremes->get($annee.'|'.$nature);
            if ($raison === null && $bareme === null) {
                $raison = 'barème '.$nature.' '.$annee.' non validé';
            }
            if ($raison !== null) {
                $ecartes[$enseignant->name.' — '.$raison] = ['enseignant' => $enseignant->name, 'raison' => $raison];

                continue;
            }

            if ($nature === PointagesEnseignant::SURVEILLANCE) {
                $ligne = $this->ligne($etat, $enseignant->id, $ecue->id, $nature, (float) $groupe->count(), $bareme, 0.0, null);
            } else {
                $heures = round($groupe->sum('minutes_payables') / 60, 4);
                $alloue = $this->alloue($enseignant->id, $ecue->id, $nature);
                $deja = (float) LigneEtatPaie::query()->where('enseignant_id', $enseignant->id)->where('maquette_ecue_id', $ecue->id)->where('nature', $nature)
                    ->whereHas('etat', fn ($q) => $q->where('statut', '<>', EtatPaie::REJETE))->sum('quantite');
                $disponible = max(0.0, $alloue - $deja);
                $payable = max(0.0, min($heures, $disponible));
                $hors = round(max(0.0, $heures - $disponible), 2);
                $notes = [];
                if ($dossier->statut_paie === 'permanent' && ($dossier->service_du_heures ?? 0) > 0) {
                    $restantService[$enseignant->id] ??= $this->restantService($dossier, $annee, $etat);
                    $impute = min($restantService[$enseignant->id], $payable);
                    $restantService[$enseignant->id] -= $impute;
                    $payable -= $impute;
                    if ($impute > 0) {
                        $notes[] = $this->h($impute).' h imputées sur le service dû';
                    }
                }
                if ($payable <= 0) {
                    continue;
                }
                if ($bareme->montant_unitaire === 0) {
                    $notes[] = 'Heures de '.$nature.' non rémunérées';
                }
                if ($hors > 0) {
                    $notes[] = $this->h($hors).' h au-delà des heures allouées, non payées';
                }
                $ligne = $this->ligne($etat, $enseignant->id, $ecue->id, $nature, $payable, $bareme, $hors, $notes === [] ? null : implode(' ; ', $notes));
            }
            PointageEnseignant::query()->whereKey($groupe->pluck('id'))->update(['ligne_paie_id' => $ligne->id, 'updated_at' => now()]);
            $n++;
        }
        $etat->forceFill(['ecartes' => array_values($ecartes)])->save();

        return $n;
    }

    // ---------------------------------------------------------------- circuit

    /** Fait avancer l'état d'une étape (vérifié, visé, transmis, pris en charge, payé). */
    public function avancer(EtatPaie $etat, string $etape, User $auteur, ?string $reference = null, ?string $date = null): void
    {
        [$depuis, $permission] = self::ETAPES[$etape] ?? throw new InvalidArgumentException('Étape inconnue.');
        abort_unless($auteur->can($permission), 403);
        DB::transaction(function () use ($etat, $etape, $depuis, $auteur, $reference, $date): void {
            $e = EtatPaie::query()->whereKey($etat->id)->lockForUpdate()->firstOrFail();
            $visa = $this->visaRequis();
            $admis = match ($etape) {
                EtatPaie::VISE => $visa ? $depuis : [],
                EtatPaie::TRANSMIS => $visa ? [EtatPaie::VISE] : [EtatPaie::VERIFIE],
                default => $depuis,
            };
            if (! in_array($e->statut, $admis, true)) {
                throw new InvalidArgumentException('Étape « '.EtatPaie::STATUTS[$etape].' » impossible depuis « '.EtatPaie::STATUTS[$e->statut].' »'
                    .($etape === EtatPaie::TRANSMIS && $visa ? ' : le visa du contrôleur interne est requis.' : ($etape === EtatPaie::VISE && ! $visa ? ' : le visa n\'est pas requis.' : '.')));
            }
            $reference = trim((string) $reference);
            $champs = ['statut' => $etape];
            switch ($etape) {
                case EtatPaie::VERIFIE:
                    $champs += ['verifie_par' => $auteur->id, 'verifie_at' => now()];
                    break;
                case EtatPaie::VISE:
                    $champs += ['vise_par' => $auteur->id, 'vise_at' => now()];
                    break;
                case EtatPaie::TRANSMIS:
                    if ($reference === '') {
                        throw new InvalidArgumentException('La référence du bordereau de transmission est obligatoire.');
                    }
                    $champs += ['transmis_par' => $auteur->id, 'transmis_at' => now(), 'reference_bordereau' => mb_substr($reference, 0, 100)];
                    break;
                case EtatPaie::PRIS_EN_CHARGE:
                    $champs += ['pris_en_charge_par' => $auteur->id, 'pris_en_charge_at' => now()];
                    break;
                case EtatPaie::PAYE:
                    if ($reference === '' || blank($date)) {
                        throw new InvalidArgumentException('La date et la référence du virement sont obligatoires.');
                    }
                    $jour = CarbonImmutable::parse((string) $date);
                    if ($jour->greaterThan(now('Africa/Douala')->endOfDay())) {
                        throw new InvalidArgumentException('La date du virement ne peut pas être dans le futur.');
                    }
                    $champs += ['paye_le' => $jour->toDateString(), 'reference_paiement' => mb_substr($reference, 0, 100)];
                    break;
            }
            $e->forceFill($champs)->save();
        });
    }

    /** Rejet motivé avant la prise en charge : les pointages sont libérés pour un nouvel état du mois. */
    public function rejeter(EtatPaie $etat, string $motif, User $auteur): void
    {
        DB::transaction(function () use ($etat, $motif, $auteur): void {
            $e = EtatPaie::query()->whereKey($etat->id)->lockForUpdate()->firstOrFail();
            $peut = $auteur->can(PermissionsLmd::PAIE_VERIFIER) || ($e->statut === EtatPaie::TRANSMIS && $auteur->can(PermissionsLmd::PAIE_PAYER));
            abort_unless($peut, 403);
            if (! in_array($e->statut, EtatPaie::REJETABLES, true)) {
                throw new InvalidArgumentException('Un état pris en charge ou payé ne se rejette pas : préparez un état rectificatif.');
            }
            $motif = trim($motif);
            if (mb_strlen($motif) < 5) {
                throw new InvalidArgumentException('Le rejet exige un motif.');
            }
            $this->liberer($e);
            $e->forceFill(['statut' => EtatPaie::REJETE, 'motif_rejet' => mb_substr($motif, 0, 500)])->save();
        });
    }

    public function visaRequis(): bool
    {
        return (bool) AppSettings::get(self::CLE_VISA, false);
    }

    public function definirVisa(bool $actif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_VERIFIER), 403);
        AppSettings::set(self::CLE_VISA, $actif, $auteur->id);
    }

    // ---------------------------------------------------------------- barème

    /** Modifie une ligne du barème ; une ligne validée repasse à « proposé » et doit être revalidée. */
    public function modifierBareme(BaremeRemuneration $b, int $montant, float $taux, ?string $reference, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_PREPARER), 403);
        if ($montant < 0 || $montant > 10_000_000) {
            throw new InvalidArgumentException('Le montant unitaire est un nombre de FCFA positif.');
        }
        if ($taux < 0 || $taux > 100) {
            throw new InvalidArgumentException('Le taux de retenue est compris entre 0 et 100 %.');
        }
        $b->forceFill([
            'montant_unitaire' => $montant, 'taux_retenue' => round($taux, 2),
            'reference_texte' => blank($reference) ? null : mb_substr(trim((string) $reference), 0, 255),
            'statut' => 'propose', 'valide_par' => null, 'valide_at' => null,
        ])->save();
    }

    public function validerBareme(BaremeRemuneration $b, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_VERIFIER), 403);
        if (blank($b->reference_texte)) {
            throw new InvalidArgumentException('Indiquez la référence du texte qui fixe ce montant avant de le valider.');
        }
        $b->forceFill(['statut' => 'valide', 'valide_par' => $auteur->id, 'valide_at' => now()])->save();
    }

    // ---------------------------------------------------------------- restitution

    /**
     * Une ligne par enseignant pour l'état imprimable, et les totaux.
     *
     * @return array{lignes: list<array<string, mixed>>, totaux: array<string, float|int>}
     */
    public function synthese(EtatPaie $etat): array
    {
        $lignes = $etat->lignes()->with(['enseignant', 'ecue'])->get();
        $dossiers = DossierPaieEnseignant::query()->whereIn('enseignant_id', $lignes->pluck('enseignant_id')->unique())->get()->keyBy('enseignant_id');
        $parEnseignant = $lignes->groupBy('enseignant_id')->map(function (Collection $l, int $id) use ($dossiers): array {
            $cours = $l->whereIn('nature', self::COURS);

            return [
                'enseignant' => $l->first()->enseignant->name,
                'statut' => DossierPaieEnseignant::STATUTS[$dossiers->get($id)?->statut_paie] ?? '—',
                'heures_cm' => round($l->where('nature', 'CM')->sum('quantite'), 2),
                'heures_td_tp' => round($l->whereIn('nature', ['TD', 'TP'])->sum('quantite'), 2),
                'hors_quota' => round($l->sum('heures_hors_quota'), 2),
                'surveillances' => (int) $l->where('nature', PointagesEnseignant::SURVEILLANCE)->sum('quantite'),
                'cours' => (int) $cours->sum('montant_brut'),
                'brut' => (int) $l->sum('montant_brut'),
                'retenue' => (int) $l->sum('montant_retenue'),
                'net' => (int) $l->sum('montant_net'),
            ];
        })->sortBy('enseignant')->values()->all();

        return [
            'lignes' => $parEnseignant,
            'totaux' => [
                'enseignants' => count($parEnseignant),
                'heures_cm' => round(array_sum(array_column($parEnseignant, 'heures_cm')), 2),
                'surveillances' => array_sum(array_column($parEnseignant, 'surveillances')),
                'brut' => array_sum(array_column($parEnseignant, 'brut')),
                'retenue' => array_sum(array_column($parEnseignant, 'retenue')),
                'net' => array_sum(array_column($parEnseignant, 'net')),
            ],
        ];
    }

    public static function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', ' ').' FCFA';
    }

    // ---------------------------------------------------------------- interne

    private function ligne(EtatPaie $etat, int $enseignantId, int $ecueId, string $nature, float $quantite, BaremeRemuneration $b, float $hors, ?string $observation): LigneEtatPaie
    {
        $brut = (int) round($quantite * $b->montant_unitaire);
        $retenue = (int) round($quantite * $b->montant_unitaire * $b->taux_retenue / 100);
        $l = (new LigneEtatPaie)->forceFill([
            'etat_id' => $etat->id, 'enseignant_id' => $enseignantId, 'maquette_ecue_id' => $ecueId, 'nature' => $nature,
            'quantite' => round($quantite, 4), 'montant_unitaire' => $b->montant_unitaire, 'taux_retenue' => $b->taux_retenue,
            'montant_brut' => $brut, 'montant_retenue' => $retenue, 'montant_net' => $brut - $retenue,
            'heures_hors_quota' => $hors, 'observation' => $observation === null ? null : mb_substr($observation, 0, 500),
        ]);
        $l->save();

        return $l;
    }

    /** Heures allouées à l'enseignant pour une nature sur l'ECUE (programmations non annulées ni déclinées). */
    private function alloue(int $enseignantId, int $ecueId, string $nature): float
    {
        $colonne = ['CM' => 'heures_cm', 'TD' => 'heures_td', 'TP' => 'heures_tp'][$nature];

        return (float) ProgrammationEnseignant::query()->where('enseignant_id', $enseignantId)
            ->whereNotIn('statut', [ProgrammationEnseignant::ANNULE, ProgrammationEnseignant::DECLINE])
            ->whereHas('quota', fn ($q) => $q->where('maquette_ecue_id', $ecueId))->sum($colonne);
    }

    /** Service dû restant d'un permanent au début de l'état : service dû moins les heures de cours validées avant le mois ou déjà payées. */
    private function restantService(DossierPaieEnseignant $dossier, string $annee, EtatPaie $etat): float
    {
        $minutes = (int) PointageEnseignant::query()->where('enseignant_id', $dossier->enseignant_id)->where('statut', PointageEnseignant::VALIDE)
            ->whereIn('nature', self::COURS)
            ->whereHas('seance.ecue.maquetteUe.maquette', fn ($q) => $q->where('annee_academique', $annee))
            ->where(fn ($q) => $q->whereNotNull('ligne_paie_id')
                ->orWhereHas('seance', fn ($s) => $s->where('date_seance', '<', $etat->periode_du->toDateString())))
            ->sum('minutes_payables');

        return max(0.0, (float) $dossier->service_du_heures - $minutes / 60);
    }

    /** Les pointages portés sur l'état redeviennent libres. */
    private function liberer(EtatPaie $etat): void
    {
        PointageEnseignant::query()->whereIn('ligne_paie_id', $etat->lignes()->select('id'))->update(['ligne_paie_id' => null, 'updated_at' => now()]);
    }

    private function h(float $h): string
    {
        return rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',');
    }
}
