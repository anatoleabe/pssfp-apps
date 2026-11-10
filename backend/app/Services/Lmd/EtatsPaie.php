<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\BaremeRemuneration;
use App\Models\DossierPaieEnseignant;
use App\Models\EtatPaie;
use App\Models\LigneEtatPaie;
use App\Models\PointageEnseignant;
use App\Models\ProgrammationEnseignant;
use App\Models\Seance;
use App\Models\SyntheseAnimation;
use App\Models\User;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * États de paie mensuels des enseignants (spec lot 13b-1 ; D23, D24 ; port de generer_etat_paie, avancer_etat_paie,
 * rejeter_etat_paie). Une heure n'est payée qu'une fois, si elle est validée et comprise dans l'allocation.
 * Lot 13b-2 : les primes d'animation en ligne validées (D25) s'ajoutent à l'état du mois où le cours se termine.
 *
 * Tout pointage examiné est rattaché à une ligne, payée ou non : la ligne porte les heures payées, les heures imputées
 * au service dû d'un permanent et les heures hors quota. L'allocation consommée et le service dû restant se lisent
 * sur ces lignes (états non rejetés), jamais sur les pointages bruts.
 */
final class EtatsPaie
{
    public const CLE_VISA = 'lmd.visa_controleur_paie';

    /** Programmations qui ouvrent droit au paiement : validées, notifiées ou acceptées (pas une simple proposition). */
    public const PROGRAMMATIONS_PAYABLES = [ProgrammationEnseignant::VALIDE, ProgrammationEnseignant::NOTIFIE, ProgrammationEnseignant::ACCEPTE];

    public const PRIME = 'prime_animation';

    /** Étape du circuit → [statuts de départ admis, permission]. */
    private const ETAPES = [
        EtatPaie::VERIFIE => [[EtatPaie::BROUILLON], PermissionsLmd::PAIE_VERIFIER],
        EtatPaie::VISE => [[EtatPaie::VERIFIE], PermissionsLmd::PAIE_VISER],
        EtatPaie::TRANSMIS => [[EtatPaie::VERIFIE, EtatPaie::VISE], PermissionsLmd::PAIE_VERIFIER],
        EtatPaie::PRIS_EN_CHARGE => [[EtatPaie::TRANSMIS], PermissionsLmd::PAIE_PAYER],
        EtatPaie::PAYE => [[EtatPaie::PRIS_EN_CHARGE], PermissionsLmd::PAIE_PAYER],
    ];

    /**
     * Statuts de départ admis pour une étape, selon que le visa du contrôleur
     * interne est requis. Source unique du circuit (avancer) et de la pastille
     * « à traiter » (statutsATraiter).
     *
     * @param  list<string>  $depuis
     * @return list<string>
     */
    private static function departsAdmis(string $etape, array $depuis, bool $visa): array
    {
        return match ($etape) {
            EtatPaie::VISE => $visa ? $depuis : [],
            // Un état déjà visé se transmet même si le visa a été désactivé depuis.
            EtatPaie::TRANSMIS => $visa ? [EtatPaie::VISE] : [EtatPaie::VERIFIE, EtatPaie::VISE],
            default => $depuis,
        };
    }

    /**
     * Statuts d'où cet utilisateur peut faire avancer un état de paie : ce
     * qui l'attend dans le circuit (pastille du menu, tableau de bord).
     *
     * @return list<string>
     */
    public function statutsATraiter(User $user): array
    {
        $visa = $this->visaRequis();
        $statuts = [];
        foreach (self::ETAPES as $etape => [$depuis, $permission]) {
            if ($user->can($permission)) {
                $statuts = [...$statuts, ...self::departsAdmis($etape, $depuis, $visa)];
            }
        }

        return array_values(array_unique($statuts));
    }

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

        try {
            return DB::transaction(function () use ($debut, $auteur): EtatPaie {
                $this->verrouPaie();
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
                $this->generer($etat, $auteur);

                return $etat->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('L\'état de '.$debut->format('m/Y').' vient d\'être préparé par un autre agent.');
        }
    }

    /** État rectificatif d'un état pris en charge ou payé : il reprend les pointages validés restants du mois. */
    public function creerRectificatif(EtatPaie $origine, User $auteur): EtatPaie
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_PREPARER), 403);
        if (! in_array($origine->statut, [EtatPaie::PRIS_EN_CHARGE, EtatPaie::PAYE], true) || $origine->rectificatif_de !== null) {
            throw new InvalidArgumentException('Un rectificatif se prépare sur un état ordinaire pris en charge ou payé.');
        }

        try {
            return DB::transaction(function () use ($origine, $auteur): EtatPaie {
                $this->verrouPaie();
                if ($origine->rectificatifs()->whereNotIn('statut', [EtatPaie::REJETE, EtatPaie::PRIS_EN_CHARGE, EtatPaie::PAYE])->exists()) {
                    throw new InvalidArgumentException('Un rectificatif de cet état est déjà en cours : terminez-le ou rejetez-le d\'abord.');
                }
                if ($this->pointagesLibres($origine)->doesntExist() && $this->synthesesLibres($origine)->doesntExist()) {
                    throw new InvalidArgumentException('Aucun pointage ni aucune prime d\'animation validés du mois n\'attendent de paiement : pas de rectificatif à préparer.');
                }
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
                if ($this->generer($etat, $auteur) === 0) {
                    throw new InvalidArgumentException('Rien de payable dans ce qui reste du mois : pas de rectificatif à préparer.');
                }

                return $etat->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Ce rectificatif vient d\'être préparé par un autre agent.');
        }
    }

    /** Régénère un brouillon : ses lignes sont retirées, ses pointages libérés, puis il est recalculé. */
    public function regenerer(EtatPaie $etat, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_PREPARER), 403);
        DB::transaction(function () use ($etat, $auteur): void {
            $this->verrouPaie();
            $e = EtatPaie::query()->whereKey($etat->id)->lockForUpdate()->firstOrFail();
            if ($e->statut !== EtatPaie::BROUILLON) {
                throw new InvalidArgumentException('Seul un état en brouillon se régénère.');
            }
            $avant = $this->totaux($e);
            $this->liberer($e);
            $e->lignes()->delete();
            $this->generer($e, $auteur);
            activity('lmd')->causedBy($auteur)->performedOn($e)->event('etat_paie_regenere')
                ->withProperties(['avant' => $avant, 'apres' => $this->totaux($e)])->log('État de paie régénéré');
        });
    }

    /**
     * Génère les lignes d'un état en brouillon (sous le verrou de la paie). Retourne le nombre de lignes.
     */
    public function generer(EtatPaie $etat, User $auteur): int
    {
        if ($etat->statut !== EtatPaie::BROUILLON) {
            throw new InvalidArgumentException('Seul un état en brouillon se génère.');
        }
        $this->verrouPaie();
        $pointages = $this->pointagesLibres($etat)->with(['enseignant', 'seance.ecue.maquetteUe.maquette'])->orderBy('id')->lockForUpdate()->get();
        $syntheses = $this->synthesesLibres($etat)->with(['enseignant', 'ecue', 'activites', 'programmation'])->orderBy('id')->lockForUpdate()->get();
        $dossiers = DossierPaieEnseignant::query()->whereIn('enseignant_id', $pointages->pluck('enseignant_id')->merge($syntheses->pluck('enseignant_id'))->unique())
            ->get()->keyBy('enseignant_id');
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
            $bareme = $baremes->get($annee.'|'.$nature);
            $raison = $this->raisonEcart($dossier, $bareme, $nature, $annee);
            if ($raison !== null) {
                // Écarté : ses pointages restent libres pour un état suivant, une fois la raison levée.
                $ecartes[$enseignant->name.' — '.$raison] = ['enseignant' => $enseignant->name, 'raison' => $raison];

                continue;
            }

            if ($nature === PointagesEnseignant::SURVEILLANCE) {
                $ligne = $this->ligne($etat, $enseignant->id, $ecue->id, $annee, $nature, (float) $groupe->count(), 0.0, 0.0, $bareme, null);
            } else {
                $heures = round($groupe->sum('minutes_payables') / 60, 4);
                $disponible = max(0.0, $this->alloue($enseignant->id, $ecue->id, $nature) - $this->consomme($enseignant->id, $ecue->id, $nature));
                $dansQuota = min($heures, $disponible);
                $hors = round($heures - $dansQuota, 2);
                $service = 0.0;
                if ($dossier->statut_paie === 'permanent' && ($dossier->service_du_heures ?? 0) > 0) {
                    $restantService[$enseignant->id] ??= max(0.0, (float) $dossier->service_du_heures - $this->serviceImpute($enseignant->id, $annee));
                    $service = min($restantService[$enseignant->id], $dansQuota);
                    $restantService[$enseignant->id] -= $service;
                }
                $payable = round($dansQuota - $service, 4);
                if ($payable <= 0 && $service <= 0 && $hors <= 0) {
                    continue;   // aucune heure payable (pointage de quelques minutes) : rien à porter
                }
                $notes = array_values(array_filter([
                    $service > 0 ? $this->h($service).' h imputées sur le service dû' : null,
                    $payable > 0 && $bareme->montant_unitaire === 0 ? 'Heures de '.$nature.' non rémunérées' : null,
                    $hors > 0 ? $this->h($hors).' h au-delà des heures allouées, non payées' : null,
                ]));
                $ligne = $this->ligne($etat, $enseignant->id, $ecue->id, $annee, $nature, $payable, round($service, 2), $hors, $bareme, $notes === [] ? null : implode(' ; ', $notes));
            }
            $rattaches = PointageEnseignant::query()->whereKey($groupe->pluck('id'))->whereNull('ligne_paie_id')
                ->update(['ligne_paie_id' => $ligne->id, 'updated_at' => now()]);
            if ($rattaches !== $groupe->count()) {
                throw new RuntimeException('Des pointages ont été rattachés à un autre état pendant la génération : recommencez.');
            }
            $n++;
        }
        $n += $this->genererPrimes($etat, $syntheses, $dossiers, $baremes, $ecartes);
        $etat->forceFill(['ecartes' => array_values($ecartes), 'genere_par' => $auteur->id, 'genere_at' => now()])->save();

        return $n;
    }

    // ---------------------------------------------------------------- circuit

    /**
     * Fait avancer l'état d'une étape. Chaque étape est faite par une autre personne que les précédentes ;
     * la vérification porte sur la version de l'état affichée au vérificateur (empreinte).
     */
    public function avancer(EtatPaie $etat, string $etape, User $auteur, ?string $reference = null, ?string $date = null, ?string $empreinte = null): void
    {
        [$depuis, $permission] = self::ETAPES[$etape] ?? throw new InvalidArgumentException('Étape inconnue.');
        abort_unless($auteur->can($permission), 403);
        DB::transaction(function () use ($etat, $etape, $depuis, $auteur, $reference, $date, $empreinte): void {
            $e = EtatPaie::query()->whereKey($etat->id)->lockForUpdate()->firstOrFail();
            $visa = $this->visaRequis();
            $admis = self::departsAdmis($etape, $depuis, $visa);
            if (! in_array($e->statut, $admis, true)) {
                throw new InvalidArgumentException('Étape « '.EtatPaie::STATUTS[$etape].' » impossible depuis « '.EtatPaie::STATUTS[$e->statut].' »'
                    .($etape === EtatPaie::TRANSMIS && $visa ? ' : le visa du contrôleur interne est requis.' : ($etape === EtatPaie::VISE && ! $visa ? ' : le visa n\'est pas requis.' : '.')));
            }
            $this->separer($e, $etape, $auteur);
            $reference = trim((string) $reference);
            $champs = ['statut' => $etape];
            switch ($etape) {
                case EtatPaie::VERIFIE:
                    if ($e->lignes()->doesntExist()) {
                        throw new InvalidArgumentException('Aucune ligne : régénérez l\'état ou rejetez-le.');
                    }
                    if ($empreinte !== null && ! hash_equals($this->empreinte($e), $empreinte)) {
                        throw new InvalidArgumentException('L\'état a changé depuis son affichage : rechargez la page et contrôlez-le de nouveau.');
                    }
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
                    $jour = CarbonImmutable::parse((string) $date, 'Africa/Douala')->startOfDay();
                    if ($jour->greaterThan(now('Africa/Douala')->endOfDay())) {
                        throw new InvalidArgumentException('La date du virement ne peut pas être dans le futur.');
                    }
                    if ($jour->lt(CarbonImmutable::parse($e->pris_en_charge_at)->setTimezone('Africa/Douala')->startOfDay())) {
                        throw new InvalidArgumentException('Le virement ne peut pas précéder la prise en charge de l\'état.');
                    }
                    $champs += ['paye_le' => $jour->toDateString(), 'reference_paiement' => mb_substr($reference, 0, 100)];
                    break;
            }
            $e->forceFill($champs)->save();
        });
    }

    /**
     * Rejet motivé avant la prise en charge : les pointages sont libérés pour un nouvel état du mois.
     * Un état transmis ne se rejette que par le service financier, qui détient le bordereau.
     */
    public function rejeter(EtatPaie $etat, string $motif, User $auteur): void
    {
        DB::transaction(function () use ($etat, $motif, $auteur): void {
            $this->verrouPaie();
            $e = EtatPaie::query()->whereKey($etat->id)->lockForUpdate()->firstOrFail();
            if (! in_array($e->statut, EtatPaie::REJETABLES, true)) {
                throw new InvalidArgumentException('Un état pris en charge ou payé ne se rejette pas : préparez un état rectificatif.');
            }
            abort_unless($this->peutRejeter($e, $auteur), 403);
            $motif = trim($motif);
            if (mb_strlen($motif) < 5) {
                throw new InvalidArgumentException('Le rejet exige un motif.');
            }
            $this->liberer($e);
            $e->forceFill(['statut' => EtatPaie::REJETE, 'motif_rejet' => mb_substr($motif, 0, 500)])->save();
        });
    }

    public function peutRejeter(EtatPaie $e, User $auteur): bool
    {
        return $e->statut === EtatPaie::TRANSMIS ? $auteur->can(PermissionsLmd::PAIE_PAYER) : $auteur->can(PermissionsLmd::PAIE_VERIFIER);
    }

    /** Empreinte de la version affichée : statut, génération (date, dernière ligne) et totaux des lignes. */
    public function empreinte(EtatPaie $e): string
    {
        return hash('sha256', implode('|', [$e->statut, $e->genere_at?->getTimestamp(), (int) $e->lignes()->max('id'), json_encode($this->totaux($e))]));
    }

    public function visaRequis(): bool
    {
        return (bool) AppSettings::get(self::CLE_VISA, false);
    }

    /** Activer le visa est un réglage d'administration : ni la Scolarité ni le contrôleur ne le règlent. */
    public function definirVisa(bool $actif, User $auteur): void
    {
        abort_unless($auteur->can('settings.manage'), 403);
        AppSettings::set(self::CLE_VISA, $actif, $auteur->id);
        activity('lmd')->causedBy($auteur)->event('visa_paie_regle')->withProperties(['actif' => $actif])->log('Visa du contrôleur interne '.($actif ? 'activé' : 'désactivé'));
    }

    // ---------------------------------------------------------------- barème

    /** Modifie une ligne du barème ; une ligne validée qui change repasse à « proposé » et doit être revalidée par un autre. */
    public function modifierBareme(BaremeRemuneration $b, int $montant, float $taux, ?string $reference, User $auteur): bool
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_PREPARER), 403);
        if ($montant < 0 || $montant > 1_000_000) {
            throw new InvalidArgumentException('Le montant unitaire est compris entre 0 et 1 000 000 FCFA.');
        }
        if ($taux < 0 || $taux > 100) {
            throw new InvalidArgumentException('Le taux de retenue est compris entre 0 et 100 %.');
        }
        $b->forceFill(['montant_unitaire' => $montant, 'taux_retenue' => round($taux, 2), 'reference_texte' => blank($reference) ? null : mb_substr(trim((string) $reference), 0, 255)]);
        if (! $b->isDirty()) {
            return false;
        }
        $b->forceFill(['statut' => 'propose', 'modifie_par' => $auteur->id, 'valide_par' => null, 'valide_at' => null])->save();

        return true;
    }

    public function validerBareme(BaremeRemuneration $b, User $auteur, ?int $montantVu = null): void
    {
        abort_unless($auteur->can(PermissionsLmd::PAIE_VERIFIER), 403);
        DB::transaction(function () use ($b, $auteur, $montantVu): void {
            $l = BaremeRemuneration::query()->whereKey($b->id)->lockForUpdate()->firstOrFail();
            if ($montantVu !== null && $l->montant_unitaire !== $montantVu) {
                throw new InvalidArgumentException('Le montant a changé depuis son affichage : rechargez la page.');
            }
            if (blank($l->reference_texte)) {
                throw new InvalidArgumentException('Indiquez la référence du texte qui fixe ce montant avant de le valider.');
            }
            if ($l->modifie_par !== null && $l->modifie_par === $auteur->id) {
                throw new InvalidArgumentException('Vous avez modifié cette ligne : un autre responsable la valide.');
            }
            $l->forceFill(['statut' => 'valide', 'valide_par' => $auteur->id, 'valide_at' => now()])->save();
        });
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
            return [
                'enseignant' => $l->first()->enseignant->name,
                'statut' => DossierPaieEnseignant::STATUTS[$dossiers->get($id)?->statut_paie] ?? '—',
                'heures_cm' => round($l->where('nature', 'CM')->sum('quantite'), 2),
                'heures_td_tp' => round($l->whereIn('nature', ['TD', 'TP'])->sum('quantite'), 2),
                'service' => round($l->sum('heures_service'), 2),
                'hors_quota' => round($l->sum('heures_hors_quota'), 2),
                'surveillances' => (int) $l->where('nature', PointagesEnseignant::SURVEILLANCE)->sum('quantite'),
                'primes' => (int) $l->where('nature', self::PRIME)->sum('montant_brut'),
                'nb_primes' => $l->where('nature', self::PRIME)->count(),
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
                'service' => round(array_sum(array_column($parEnseignant, 'service')), 2),
                'hors_quota' => round(array_sum(array_column($parEnseignant, 'hors_quota')), 2),
                'surveillances' => array_sum(array_column($parEnseignant, 'surveillances')),
                'primes' => array_sum(array_column($parEnseignant, 'primes')),
                'nb_primes' => array_sum(array_column($parEnseignant, 'nb_primes')),
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

    /** Une seule génération de paie à la fois (verrou transactionnel PostgreSQL, ADR-0011). */
    private function verrouPaie(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('lmd_paie_enseignants'))");
    }

    /** Pointages validés non rattachés dont la séance (active) tombe dans la période de l'état. */
    private function pointagesLibres(EtatPaie $etat): Builder
    {
        return PointageEnseignant::query()->where('statut', PointageEnseignant::VALIDE)->whereNull('ligne_paie_id')
            ->whereHas('seance', fn ($q) => $q->whereIn('statut', Seance::ACTIVES)->whereNotNull('maquette_ecue_id')
                ->whereBetween('date_seance', [$etat->periode_du->toDateString(), $etat->periode_au->toDateString()]));
    }

    /** Synthèses d'animation validées, non rattachées, dont le cours se termine dans la période de l'état (D25). */
    private function synthesesLibres(EtatPaie $etat): Builder
    {
        return SyntheseAnimation::query()->where('statut', SyntheseAnimation::VALIDE)->whereNull('ligne_paie_id')
            ->whereBetween('periode_au', [$etat->periode_du->toDateString(), $etat->periode_au->toDateString()]);
    }

    /** Raison pour laquelle un enseignant n'est pas porté sur l'état, ou null. */
    private function raisonEcart(?DossierPaieEnseignant $dossier, ?BaremeRemuneration $bareme, string $nature, string $annee): ?string
    {
        return match (true) {
            $dossier === null => 'pas de dossier de paie',
            ! $dossier->actif => 'dossier de paie inactif',
            ! $dossier->estComplet() => implode(' et ', $dossier->manques()).' manquant(s)',
            $bareme === null => 'barème '.$nature.' '.$annee.' non validé',
            default => null,
        };
    }

    /**
     * Une ligne « prime d'animation » par synthèse validée du mois : quantité = coefficient (prorata ou seuil).
     * Une synthèse dont la prime est nulle, ou d'un enseignant écarté, reste libre.
     *
     * @param  Collection<int, SyntheseAnimation>  $syntheses
     * @param  Collection<int, DossierPaieEnseignant>  $dossiers
     * @param  Collection<string, BaremeRemuneration>  $baremes
     * @param  array<string, array{enseignant: string, raison: string}>  $ecartes
     */
    private function genererPrimes(EtatPaie $etat, Collection $syntheses, Collection $dossiers, Collection $baremes, array &$ecartes): int
    {
        $calcul = app(SynthesesAnimation::class);
        $n = 0;
        foreach ($syntheses as $s) {
            $coefficient = $calcul->coefficient($s);
            if ($coefficient <= 0) {
                continue;
            }
            $bareme = $baremes->get($s->annee_academique.'|'.self::PRIME);
            $raison = in_array($s->programmation?->statut, self::PROGRAMMATIONS_PAYABLES, true)
                ? $this->raisonEcart($dossiers->get($s->enseignant_id), $bareme, self::PRIME, $s->annee_academique)
                : 'programmation de '.$s->ecue?->code.' déclinée ou annulée (prime non due)';
            if ($raison !== null) {
                $ecartes[$s->enseignant->name.' — '.$raison] = ['enseignant' => $s->enseignant->name, 'raison' => $raison];

                continue;
            }
            $taux = str_replace('.', ',', (string) $calcul->taux($s)['global']);
            $ligne = $this->ligne($etat, $s->enseignant_id, $s->maquette_ecue_id, $s->annee_academique, self::PRIME, $coefficient, 0.0, 0.0, $bareme,
                'Animation en ligne du cours : '.$taux.' % réalisé'.($calcul->mode() === SynthesesAnimation::SEUIL ? ' (seuil atteint)' : ''));
            $rattachees = SyntheseAnimation::query()->whereKey($s->id)->where('statut', SyntheseAnimation::VALIDE)->whereNull('ligne_paie_id')
                ->update(['ligne_paie_id' => $ligne->id, 'updated_at' => now()]);
            if ($rattachees !== 1) {
                throw new RuntimeException('Une prime d\'animation a été rattachée à un autre état pendant la génération : recommencez.');
            }
            $n++;
        }

        return $n;
    }

    /**
     * Seuls les montants sont arrondis au franc : brut = arrondi(quantité × montant) ; retenue = arrondi(brut × taux).
     */
    private function ligne(EtatPaie $etat, int $enseignantId, int $ecueId, string $annee, string $nature, float $quantite, float $service, float $hors,
        BaremeRemuneration $b, ?string $observation): LigneEtatPaie
    {
        $quantite = round($quantite, 4);
        $brut = (int) round($quantite * $b->montant_unitaire);
        $retenue = (int) round($brut * $b->taux_retenue / 100);
        $l = (new LigneEtatPaie)->forceFill([
            'etat_id' => $etat->id, 'enseignant_id' => $enseignantId, 'maquette_ecue_id' => $ecueId, 'annee_academique' => $annee, 'nature' => $nature,
            'quantite' => $quantite, 'heures_service' => $service, 'heures_hors_quota' => $hors,
            'montant_unitaire' => $b->montant_unitaire, 'taux_retenue' => $b->taux_retenue,
            'montant_brut' => $brut, 'montant_retenue' => $retenue, 'montant_net' => $brut - $retenue,
            'observation' => $observation === null ? null : mb_substr($observation, 0, 500),
        ]);
        $l->save();

        return $l;
    }

    /** Heures allouées à l'enseignant pour une nature sur l'ECUE (programmations validées, notifiées ou acceptées). */
    private function alloue(int $enseignantId, int $ecueId, string $nature): float
    {
        $colonne = ['CM' => 'heures_cm', 'TD' => 'heures_td', 'TP' => 'heures_tp'][$nature];

        return (float) ProgrammationEnseignant::query()->where('enseignant_id', $enseignantId)->whereIn('statut', self::PROGRAMMATIONS_PAYABLES)
            ->whereHas('quota', fn ($q) => $q->where('maquette_ecue_id', $ecueId))->sum($colonne);
    }

    /** Allocation déjà consommée sur l'ECUE pour la nature : heures payées et heures imputées au service dû (états non rejetés). */
    private function consomme(int $enseignantId, int $ecueId, string $nature): float
    {
        return (float) LigneEtatPaie::query()->where('enseignant_id', $enseignantId)->where('maquette_ecue_id', $ecueId)->where('nature', $nature)
            ->whereHas('etat', fn ($q) => $q->where('statut', '<>', EtatPaie::REJETE))
            ->sum(DB::raw('quantite + heures_service'));
    }

    /** Service dû déjà imputé dans l'année (états non rejetés). */
    private function serviceImpute(int $enseignantId, string $annee): float
    {
        return (float) LigneEtatPaie::query()->where('enseignant_id', $enseignantId)->where('annee_academique', $annee)
            ->whereHas('etat', fn ($q) => $q->where('statut', '<>', EtatPaie::REJETE))->sum('heures_service');
    }

    /**
     * Séparation des tâches : qui prépare ou génère ne vérifie pas ; le contrôleur et le service financier sont d'autres
     * personnes ; personne ne vérifie un état où figurent ses propres heures.
     */
    private function separer(EtatPaie $e, string $etape, User $auteur): void
    {
        $precedents = match ($etape) {
            EtatPaie::VERIFIE => [$e->cree_par, $e->genere_par],
            EtatPaie::VISE => [$e->cree_par, $e->genere_par, $e->verifie_par],
            EtatPaie::PRIS_EN_CHARGE, EtatPaie::PAYE => [$e->cree_par, $e->genere_par, $e->verifie_par, $e->transmis_par],
            default => [],
        };
        if (in_array($auteur->id, array_filter($precedents), true)) {
            throw new InvalidArgumentException('Vous avez déjà agi sur cet état (préparation, vérification ou transmission) : une autre personne fait cette étape.');
        }
        if (in_array($etape, [EtatPaie::VERIFIE, EtatPaie::VISE, EtatPaie::PRIS_EN_CHARGE], true) && $e->lignes()->where('enseignant_id', $auteur->id)->exists()) {
            throw new InvalidArgumentException('Vos propres heures figurent sur cet état : une autre personne fait cette étape.');
        }
    }

    /** @return array{lignes: int, brut: int, net: int} */
    private function totaux(EtatPaie $e): array
    {
        return ['lignes' => $e->lignes()->count(), 'brut' => (int) $e->lignes()->sum('montant_brut'), 'net' => (int) $e->lignes()->sum('montant_net')];
    }

    /** Les pointages et les primes d'animation portés sur l'état redeviennent libres. */
    private function liberer(EtatPaie $etat): void
    {
        PointageEnseignant::query()->whereIn('ligne_paie_id', $etat->lignes()->select('id'))->update(['ligne_paie_id' => null, 'updated_at' => now()]);
        SyntheseAnimation::query()->whereIn('ligne_paie_id', $etat->lignes()->select('id'))->update(['ligne_paie_id' => null, 'updated_at' => now()]);
    }

    private function h(float $h): string
    {
        return rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',');
    }
}
