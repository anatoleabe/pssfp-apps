<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\DossierPaieEnseignant;
use App\Models\EmploiDuTemps;
use App\Models\EvenementCalendrier;
use App\Models\MaquetteEcue;
use App\Models\PointageEnseignant;
use App\Models\ProgrammationEnseignant;
use App\Models\Seance;
use App\Models\User;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pointage des cours enseignés (spec lot 13a ; D23) : émargement de l'enseignant (arrivée, départ, cahier de textes),
 * validation ou rejet par la Scolarité, heures effectives figées à la validation ; dossier de paie de l'enseignant.
 */
final class PointagesEnseignant
{
    public const CLE_ARRONDI = 'lmd.arrondi_paie_minutes';

    public const CLE_DELAI = 'lmd.delai_validation_pointage_jours';

    public const FUSEAU = 'Africa/Douala';

    private const ARRONDI_DEFAUT = 15;

    private const DELAI_DEFAUT = 7;

    private const NATURES = ['CM', 'TD', 'TP'];

    private const LIMITE_LISTE = 200;

    /** Programmations qui autorisent un remplacement (D23) : validées, notifiées ou acceptées. */
    private const REMPLACEMENT = [ProgrammationEnseignant::VALIDE, ProgrammationEnseignant::NOTIFIE, ProgrammationEnseignant::ACCEPTE];

    // ------------------------------------------------------------ émargement

    /**
     * Séances que l'enseignant peut pointer : les siennes, ou celles d'un cours où il a une programmation validée, notifiée
     * ou acceptée pour la même période (remplacement) ; passées ou du jour, sans pointage retenu d'un autre enseignant.
     *
     * @return Collection<int, Seance>
     */
    public function seancesAEmarger(User $enseignant): Collection
    {
        return $this->seancesPointables()
            ->where(fn (Builder $q) => $q->where('enseignant_id', $enseignant->id)->orWhereExists($this->programmationSurSeance($enseignant)))
            ->whereDoesntHave('pointagesEnseignant', fn ($q) => $q->where(fn ($x) => $x->where('enseignant_id', $enseignant->id)->orWhere('statut', '<>', PointageEnseignant::REJETE)))
            ->with(['ecue', 'enseignant'])->orderByDesc('date_seance')->orderByDesc('debut')->limit(self::LIMITE_LISTE)->get();
    }

    /**
     * Séances que la Scolarité peut pointer pour un enseignant : d'abord les siennes et celles des cours où il est programmé ;
     * une recherche (code du cours ou date JJ/MM) élargit à toutes les séances pointables.
     *
     * @return Collection<int, Seance>
     */
    public function seancesPointablesPour(User $enseignant, ?string $recherche = null): Collection
    {
        $q = $this->seancesPointables()
            ->whereDoesntHave('pointagesEnseignant', fn ($x) => $x->where('enseignant_id', $enseignant->id))
            ->with(['ecue', 'enseignant'])->orderByDesc('date_seance')->orderByDesc('debut')->limit(self::LIMITE_LISTE);
        $recherche = trim((string) $recherche);
        if ($recherche === '') {
            return $q->where(fn (Builder $x) => $x->where('enseignant_id', $enseignant->id)->orWhereExists($this->programmationSurSeance($enseignant)))->get();
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{4}))?$#', $recherche, $m) === 1) {
            return $q->whereMonth('date_seance', (int) $m[2])->whereDay('date_seance', (int) $m[1])
                ->when(isset($m[3]), fn (Builder $x) => $x->whereYear('date_seance', (int) $m[3]))->get();
        }

        return $q->whereHas('ecue', fn (Builder $x) => $x->where('code', 'ilike', '%'.addcslashes($recherche, '%_\\').'%'))->get();
    }

    /**
     * Pointe une séance. L'enseignant pointe pour lui-même (mode « signature ») ; la Scolarité, pour tout enseignant
     * (jamais en mode « signature »).
     *
     * @param  array<string, mixed>  $data  arrivee, depart (H:i), nature, contenu_enseigne, mode, confirme_delegue
     */
    public function emarger(Seance $seance, User $enseignant, array $data, User $auteur): PointageEnseignant
    {
        $pourSoi = $auteur->id === $enseignant->id && $auteur->can(PermissionsLmd::EMARGER);
        $gestion = ! $pourSoi && $auteur->can(PermissionsLmd::POINTAGE);
        abort_unless($gestion || $pourSoi, 403);
        if (! $enseignant->hasRole('enseignant')) {
            throw new InvalidArgumentException('Seul un compte qui a le rôle d\'enseignant pointe un cours.');
        }
        if (! $this->seancesPointables()->whereKey($seance->id)->exists()) {
            throw new InvalidArgumentException('Cette séance ne se pointe pas : elle doit appartenir à un emploi du temps publié, être un cours (pas une évaluation), ne pas être annulée ni reportée, et être passée ou du jour.');
        }
        if ($pourSoi && $seance->enseignant_id !== $enseignant->id
            && ! Seance::query()->whereKey($seance->id)->whereExists($this->programmationSurSeance($enseignant))->exists()) {
            throw new InvalidArgumentException('Vous n\'êtes ni l\'enseignant de cette séance, ni programmé (programmation validée) sur ce cours pour la période.');
        }
        if (PointageEnseignant::query()->where('seance_id', $seance->id)->where('enseignant_id', $enseignant->id)->exists()) {
            throw new InvalidArgumentException('Cette séance est déjà pointée pour cet enseignant.');
        }
        if ($pourSoi && PointageEnseignant::query()->where('seance_id', $seance->id)->where('statut', '<>', PointageEnseignant::REJETE)->exists()) {
            throw new InvalidArgumentException('Cette séance est déjà pointée par un autre enseignant : adressez-vous à la Scolarité.');
        }
        $mode = $pourSoi ? 'signature' : $this->mode((string) ($data['mode'] ?? 'saisie'));
        $p = (new PointageEnseignant)->forceFill([
            'seance_id' => $seance->id,
            'enseignant_id' => $enseignant->id,
            'remplace_enseignant_id' => $seance->enseignant_id !== null && $seance->enseignant_id !== $enseignant->id ? $seance->enseignant_id : null,
            'mode' => $mode,
            'saisi_par' => $auteur->id,
            ...$this->champs($seance, $data, $gestion),
        ]);
        try {
            $p->save();
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Cette séance est déjà pointée pour cet enseignant.');
        }

        return $p;
    }

    /**
     * Modifie un pointage non payé. L'enseignant modifie le sien tant qu'il n'est pas validé ; la Scolarité, tout pointage
     * (le pointage passe alors en mode « saisie », à son nom). Le pointage modifié repasse à valider.
     *
     * @param  array<string, mixed>  $data
     */
    public function modifier(PointageEnseignant $p, array $data, User $auteur): void
    {
        $pourSoi = $p->enseignant_id === $auteur->id && $auteur->can(PermissionsLmd::EMARGER);
        $gestion = ! $pourSoi && $auteur->can(PermissionsLmd::POINTAGE);
        abort_unless($gestion || $pourSoi, 403);
        $this->nonPaye($p);
        if (! $gestion && $p->statut === PointageEnseignant::VALIDE) {
            throw new InvalidArgumentException('Ce pointage est validé : seule la Scolarité le modifie.');
        }
        $etaitValide = $p->statut === PointageEnseignant::VALIDE;
        DB::transaction(function () use ($p, $data, $auteur, $gestion, $etaitValide): void {
            $p->forceFill([
                ...$this->champs($p->seance, $data, $gestion),
                ...($gestion ? ['mode' => $this->mode((string) ($data['mode'] ?? 'saisie')), 'saisi_par' => $auteur->id] : []),
                'statut' => PointageEnseignant::A_VALIDER, 'minutes_payables' => null, 'valide_par' => null, 'valide_at' => null, 'motif_rejet' => null,
            ])->save();
            if ($etaitValide) {
                $this->detenir($p->seance_id);
            }
        });
    }

    /**
     * Valide un pointage : heures figées, séance « tenue ». Personne ne valide ses propres heures ni sa propre saisie ;
     * une séance n'a qu'un pointage validé.
     */
    public function valider(PointageEnseignant $p, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::POINTAGE), 403);
        if ($p->statut !== PointageEnseignant::A_VALIDER) {
            throw new InvalidArgumentException('Seul un pointage à valider se valide.');
        }
        if (! $this->peutValider($p, $auteur)) {
            throw new InvalidArgumentException('Vous ne validez ni vos propres heures, ni un pointage que vous avez saisi.');
        }
        if ($p->arrivee === null || $p->depart === null || blank($p->contenu_enseigne)) {
            throw new InvalidArgumentException('Pour valider, le pointage donne l\'arrivée, le départ et le contenu enseigné (cahier de textes).');
        }
        $autre = PointageEnseignant::query()->with('enseignant')->where('seance_id', $p->seance_id)->whereKeyNot($p->id)
            ->where('statut', PointageEnseignant::VALIDE)->first();
        if ($autre !== null) {
            throw new InvalidArgumentException('Cette séance a déjà un pointage validé ('.$autre->enseignant->name.') : rejetez l\'un des deux pointages.');
        }
        DB::transaction(function () use ($p, $auteur): void {
            $p->forceFill([
                'statut' => PointageEnseignant::VALIDE, 'minutes_payables' => $this->minutesPayables($p),
                'valide_par' => $auteur->id, 'valide_at' => now(),
            ])->save();
            Seance::query()->whereKey($p->seance_id)->where('statut', 'prevue')->update(['statut' => 'tenue', 'updated_at' => now()]);
        });
    }

    /** Séparation des tâches : ni ses propres heures, ni sa propre saisie. */
    public function peutValider(PointageEnseignant $p, User $auteur): bool
    {
        return $p->enseignant_id !== $auteur->id && $p->saisi_par !== $auteur->id;
    }

    public function rejeter(PointageEnseignant $p, string $motif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::POINTAGE), 403);
        if ($p->statut !== PointageEnseignant::A_VALIDER) {
            throw new InvalidArgumentException('Seul un pointage à valider se rejette.');
        }
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new InvalidArgumentException('Le rejet exige un motif.');
        }
        $p->forceFill(['statut' => PointageEnseignant::REJETE, 'motif_rejet' => mb_substr($motif, 0, 500)])->save();
    }

    /**
     * Durée payable : durée effective plafonnée à la durée prévue de la séance, arrondie au pas inférieur.
     */
    public function minutesPayables(PointageEnseignant $p): int
    {
        $effectives = $p->minutesEffectives() ?? 0;
        $pas = $this->arrondi();

        return intdiv(min($effectives, $p->seance->duree()), $pas) * $pas;
    }

    // ------------------------------------------------------------ paramètres

    public function arrondi(): int
    {
        $v = AppSettings::get(self::CLE_ARRONDI);

        return is_numeric($v) && (int) $v > 0 ? (int) $v : self::ARRONDI_DEFAUT;
    }

    public function delai(): int
    {
        $v = AppSettings::get(self::CLE_DELAI);

        return is_numeric($v) && (int) $v > 0 ? (int) $v : self::DELAI_DEFAUT;
    }

    public function definirParametres(int $arrondi, int $delai, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::PROGRAMMATION_VALIDER), 403);
        if (! in_array($arrondi, [1, 5, 10, 15, 30], true)) {
            throw new InvalidArgumentException('Le pas d\'arrondi est de 1, 5, 10, 15 ou 30 minutes.');
        }
        if ($delai < 1 || $delai > 60) {
            throw new InvalidArgumentException('Le délai de validation est compris entre 1 et 60 jours.');
        }
        AppSettings::set(self::CLE_ARRONDI, $arrondi, $auteur->id);
        AppSettings::set(self::CLE_DELAI, $delai, $auteur->id);
    }

    // ------------------------------------------------------------ dossier de paie

    /** Ouvre le dossier de paie d'un enseignant (statut par défaut : vacataire). */
    public function ouvrirDossier(User $enseignant, User $auteur): DossierPaieEnseignant
    {
        abort_unless($auteur->can(PermissionsLmd::POINTAGE) || $auteur->can(PermissionsLmd::DOSSIER_PAIE), 403);
        if (! $enseignant->hasRole('enseignant')) {
            throw new InvalidArgumentException('Seul un compte qui a le rôle d\'enseignant a un dossier de paie.');
        }
        if (DossierPaieEnseignant::query()->where('enseignant_id', $enseignant->id)->exists()) {
            throw new InvalidArgumentException('Cet enseignant a déjà un dossier de paie.');
        }
        $d = (new DossierPaieEnseignant)->forceFill(['enseignant_id' => $enseignant->id, 'cree_par' => $auteur->id]);
        $d->save();

        return $d;
    }

    /**
     * Met à jour un dossier. Statut, catégorie, service dû et actif : Scolarité ou service financier.
     * NIU, banque et compte : service financier seulement ; les autres champs envoyés sont ignorés.
     *
     * @param  array<string, mixed>  $data
     */
    public function majDossier(DossierPaieEnseignant $d, array $data, User $auteur): void
    {
        $financier = $auteur->can(PermissionsLmd::DOSSIER_PAIE);
        abort_unless($auteur->can(PermissionsLmd::POINTAGE) || $financier, 403);
        $statut = (string) ($data['statut_paie'] ?? $d->statut_paie);
        if (! array_key_exists($statut, DossierPaieEnseignant::STATUTS)) {
            throw new InvalidArgumentException('Statut de paie inconnu.');
        }
        $categorie = blank($data['categorie'] ?? null) ? null : (string) $data['categorie'];
        if ($categorie !== null && ! array_key_exists($categorie, DossierPaieEnseignant::CATEGORIES)) {
            throw new InvalidArgumentException('Catégorie inconnue.');
        }
        $service = blank($data['service_du_heures'] ?? null) ? null : (float) $data['service_du_heures'];
        if ($service !== null && ($service < 0 || $service > 2000)) {
            throw new InvalidArgumentException('Le service dû est un nombre d\'heures compris entre 0 et 2 000.');
        }
        $champs = [
            'statut_paie' => $statut, 'categorie' => $categorie,
            'service_du_heures' => $statut === 'permanent' ? $service : null,
            'actif' => (bool) ($data['actif'] ?? $d->actif),
        ];
        if ($financier) {
            $niu = $this->code($data['niu'] ?? null, 30);
            if ($niu !== null && preg_match('/^[A-Z0-9]{8,20}$/', $niu) !== 1) {
                throw new InvalidArgumentException('Le NIU compte 8 à 20 lettres majuscules ou chiffres.');
            }
            $compte = $this->code($data['compte_paiement'] ?? null, 60);
            if ($compte !== null && preg_match('/^[A-Z0-9 ]{10,40}$/', $compte) !== 1) {
                throw new InvalidArgumentException('Le numéro de compte (RIB) compte 10 à 40 lettres majuscules, chiffres ou espaces.');
            }
            $champs += ['niu' => $niu, 'banque' => $this->texte($data['banque'] ?? null, 120), 'compte_paiement' => $compte];
        }
        $d->forceFill($champs)->save();
    }

    // ------------------------------------------------------------ suivi

    /**
     * Bilan d'une période par enseignant et par cours, à partir des allocations et des pointages : heures allouées,
     * pointées (validées et à valider), validées, à valider, au-delà de l'allocation (par nature).
     *
     * @param  array<string, array<string, float>>|null  $allocations  allocations déjà calculées (évite un second calcul)
     * @return list<array{enseignant: User, ecue: MaquetteEcue, alloue: array<string, float>, valide: array<string, float>, pointe: float, a_valider: float, rejetes: int, hors: array<string, float>}>
     */
    public function bilan(EvenementCalendrier $periode, ?array $allocations = null): array
    {
        $pointages = PointageEnseignant::query()->with(['enseignant', 'seance.ecue'])
            ->whereHas('seance.emploiDuTemps', fn ($q) => $q->where('periode_id', $periode->id))->get()
            ->groupBy(fn (PointageEnseignant $p): string => $p->enseignant_id.'-'.$p->seance->maquette_ecue_id);
        $allocations ??= $this->allocations($periode);
        $programmations = ProgrammationEnseignant::query()->with(['enseignant', 'quota.ecue'])->whereIn('statut', ProgrammationEnseignant::ACTIVES)
            ->whereHas('quota', fn ($q) => $q->where('periode_id', $periode->id))->get()
            ->keyBy(fn (ProgrammationEnseignant $p): string => $p->enseignant_id.'-'.$p->quota->maquette_ecue_id);
        $vide = ['CM' => 0.0, 'TD' => 0.0, 'TP' => 0.0];
        $sortie = [];
        foreach ($pointages->keys()->merge(array_keys($allocations))->unique() as $cle) {
            $liste = $pointages->get($cle, collect());
            $p = $programmations->get($cle);
            $enseignant = $liste->first()?->enseignant ?? $p?->enseignant;
            $ecue = $liste->first()?->seance->ecue ?? $p?->quota->ecue;
            if ($enseignant === null || $ecue === null) {
                continue;
            }
            $valides = $liste->where('statut', PointageEnseignant::VALIDE);
            $alloue = $allocations[$cle] ?? $vide;
            $valide = [];
            $hors = [];
            foreach (self::NATURES as $n) {
                $valide[$n] = round($valides->where('nature', $n)->sum('minutes_payables') / 60, 2);
                $hors[$n] = round(max(0, $valide[$n] - $alloue[$n]), 2);
            }
            $aValider = round($liste->where('statut', PointageEnseignant::A_VALIDER)->sum(fn (PointageEnseignant $x): int => $this->minutesPayables($x)) / 60, 2);
            $sortie[] = [
                'enseignant' => $enseignant, 'ecue' => $ecue, 'alloue' => $alloue, 'valide' => $valide,
                'pointe' => round(array_sum($valide) + $aValider, 2), 'a_valider' => $aValider,
                'rejetes' => $liste->where('statut', PointageEnseignant::REJETE)->count(), 'hors' => $hors,
            ];
        }
        usort($sortie, fn (array $a, array $b): int => [$a['enseignant']->name, $a['ecue']->code] <=> [$b['enseignant']->name, $b['ecue']->code]);

        return $sortie;
    }

    /**
     * Heures allouées par nature, par « enseignant-ECUE », sur les programmations actives de la période.
     *
     * @return array<string, array<string, float>>
     */
    public function allocations(EvenementCalendrier $periode): array
    {
        return ProgrammationEnseignant::query()->with('quota')->whereIn('statut', ProgrammationEnseignant::ACTIVES)
            ->whereHas('quota', fn ($q) => $q->where('periode_id', $periode->id))->get()
            ->groupBy(fn (ProgrammationEnseignant $p): string => $p->enseignant_id.'-'.$p->quota->maquette_ecue_id)
            ->map(fn (Collection $l): array => ['CM' => (float) $l->sum('heures_cm'), 'TD' => (float) $l->sum('heures_td'), 'TP' => (float) $l->sum('heures_tp')])
            ->all();
    }

    // ------------------------------------------------------------ interne

    /**
     * Séances pointables : d'un emploi du temps publié, cours (pas une évaluation), prévues ou tenues, passées ou du jour.
     *
     * @return Builder<Seance>
     */
    private function seancesPointables(): Builder
    {
        return Seance::query()->whereIn('statut', Seance::ACTIVES)
            ->whereHas('emploiDuTemps', fn (Builder $q) => $q->where('statut', EmploiDuTemps::PUBLIE))
            ->where(fn (Builder $q) => $q->whereNull('nature')->orWhereIn('nature', self::NATURES))
            ->whereDate('date_seance', '<=', now(self::FUSEAU)->toDateString());
    }

    /** Sous-requête : l'enseignant a une programmation de remplacement sur l'ECUE de la séance, pour la période de son emploi du temps. */
    private function programmationSurSeance(User $enseignant): Closure
    {
        return fn (QueryBuilder $q) => $q->selectRaw('1')->from('programmations_enseignant as pr')
            ->join('quotas_horaires as qh', 'qh.id', '=', 'pr.quota_id')
            ->join('emplois_du_temps as edt', 'edt.periode_id', '=', 'qh.periode_id')
            ->whereColumn('edt.id', 'seances.emploi_du_temps_id')
            ->whereColumn('qh.maquette_ecue_id', 'seances.maquette_ecue_id')
            ->where('pr.enseignant_id', $enseignant->id)
            ->whereIn('pr.statut', self::REMPLACEMENT);
    }

    /** Une séance sans pointage validé ni pointage d'auditeur n'est plus « tenue ». */
    private function detenir(int $seanceId): void
    {
        Seance::query()->whereKey($seanceId)->where('statut', 'tenue')
            ->whereDoesntHave('pointagesEnseignant', fn (Builder $q) => $q->where('statut', PointageEnseignant::VALIDE))
            ->whereDoesntHave('pointages')
            ->update(['statut' => 'prevue', 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function champs(Seance $seance, array $data, bool $gestion): array
    {
        $jour = $seance->date_seance->toDateString();
        $heure = function (string $cle, string $libelle) use ($data, $jour): CarbonImmutable {
            $v = trim((string) ($data[$cle] ?? ''));
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $v) !== 1) {
                throw new InvalidArgumentException('L\'heure '.$libelle.' est au format HH:MM.');
            }

            return CarbonImmutable::parse($jour.' '.substr($v, 0, 5), self::FUSEAU)->setTimezone(config('app.timezone'));
        };
        $arrivee = $heure('arrivee', 'd\'arrivée');
        $depart = $heure('depart', 'de départ');
        if ($depart->lte($arrivee)) {
            throw new InvalidArgumentException('Le départ suit l\'arrivée.');
        }
        // L'enseignant pointe la nature de la séance ; seule la Scolarité la change (D23).
        $nature = ! $gestion && in_array($seance->nature, self::NATURES, true)
            ? $seance->nature
            : (string) ($data['nature'] ?? (in_array($seance->nature, self::NATURES, true) ? $seance->nature : 'CM'));
        if (! in_array($nature, self::NATURES, true)) {
            throw new InvalidArgumentException('La nature est CM, TD ou TP.');
        }
        $contenu = $this->texte($data['contenu_enseigne'] ?? null, 5000);

        return [
            'arrivee' => $arrivee, 'depart' => $depart, 'nature' => $nature, 'contenu_enseigne' => $contenu,
            ...($gestion && array_key_exists('confirme_delegue', $data) ? ['confirme_delegue' => $data['confirme_delegue'] === null ? null : (bool) $data['confirme_delegue']] : []),
        ];
    }

    /** Mode d'une saisie par la Scolarité : jamais « signature », réservé à l'enseignant lui-même. */
    private function mode(string $mode): string
    {
        if (! array_key_exists($mode, PointageEnseignant::MODES_SAISIE)) {
            throw new InvalidArgumentException('Mode de pointage inconnu.');
        }

        return $mode;
    }

    private function nonPaye(PointageEnseignant $p): void
    {
        if ($p->estPaye()) {
            throw new InvalidArgumentException('Ce pointage est payé : il ne se modifie plus (régulariser par un état rectificatif).');
        }
    }

    /** Identifiant (NIU, RIB) : majuscules, espaces simples. */
    private function code(mixed $v, int $max): ?string
    {
        $t = $this->texte($v, $max);

        return $t === null ? null : mb_strtoupper((string) preg_replace('/\s+/', ' ', $t));
    }

    private function texte(mixed $v, int $max): ?string
    {
        $t = trim((string) ($v ?? ''));

        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
