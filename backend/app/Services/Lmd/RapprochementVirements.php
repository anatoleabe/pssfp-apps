<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\AvisVirement;
use App\Models\EtatPaie;
use App\Models\LigneEtatPaie;
use App\Models\User;
use App\Support\Journaux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lot 13c-1 (D38) : avis de virement des enseignants et rapprochement avec les états de paie.
 *
 * Port de `enregistrer_avis_virement`, `v_rapprochement_paie` et `v_ctl_rapprochement_paie`
 * (sql/25_schema_controle_gestion.sql). Le rapprochement se calcule à la lecture : rien n'est figé,
 * une annulation le corrige aussitôt.
 */
final class RapprochementVirements
{
    public const RAPPROCHE = 'rapproche';

    public const NON_VIRE = 'non_vire';

    public const PARTIEL = 'vire_partiel';

    public const EN_TROP = 'vire_en_trop';

    public const SANS_ETAT = 'vire_sans_etat';

    public const SITUATIONS = [
        self::RAPPROCHE => 'Rapproché',
        self::NON_VIRE => 'Non viré',
        self::PARTIEL => 'Viré partiel',
        self::EN_TROP => 'Viré en trop',
        self::SANS_ETAT => 'Viré sans état',
    ];

    /** États qui ont quitté la Scolarité : les seuls que l'on rapproche. */
    public const ETATS_RAPPROCHABLES = [EtatPaie::PRIS_EN_CHARGE, EtatPaie::PAYE];

    public const MOTIF_MIN = 10;

    /** Plafond d'un virement (FCFA) : au-delà, c'est une erreur de saisie. */
    public const MONTANT_MAX = 10_000_000_000;

    /** Nombre d'anomalies, mis en cache pour la pastille du menu (vidé à chaque saisie ou annulation). */
    public const CLE_CACHE = 'lmd.rapprochement_virements.anomalies';

    public static function peutSaisir(?User $user): bool
    {
        return (bool) $user?->can(PermissionsLmd::PAIE_PAYER);
    }

    public static function peutConsulter(?User $user): bool
    {
        return self::peutSaisir($user) || (bool) $user?->can(PermissionsLmd::PAIE_VERIFIER);
    }

    /**
     * Enregistre des virements, en une transaction. Une ligne identique à un avis en vigueur (même enseignant,
     * référence, montant, date et état) est un rejeu : elle ne crée rien.
     *
     * @param  list<array{enseignant_id: int, montant: int, date_valeur: string, reference: string, source: string}>  $lignes
     * @return Collection<int, AvisVirement> avis créés
     */
    public function enregistrer(?EtatPaie $etat, array $lignes, User $auteur): Collection
    {
        abort_unless(self::peutSaisir($auteur), 403);
        if ($lignes === []) {
            throw new InvalidArgumentException('Aucun virement à enregistrer.');
        }
        if ($etat !== null && ! in_array($etat->statut, self::ETATS_RAPPROCHABLES, true)) {
            throw new InvalidArgumentException('Seul un état pris en charge ou payé se rapproche des virements.');
        }
        $enseignantsDeLEtat = $etat === null ? null : $etat->lignes()->distinct()->pluck('enseignant_id')->map(fn ($id): int => (int) $id)->all();
        $dateMin = $etat?->pris_en_charge_at?->copy()->setTimezone('Africa/Douala')->toDateString();

        try {
            $crees = DB::transaction(fn (): Collection => $this->creer($etat, $lignes, $auteur, $enseignantsDeLEtat, $dateMin));
        } catch (UniqueConstraintViolationException) {
            // Deux saisies simultanées de la même référence : l'index unique a tranché.
            throw new InvalidArgumentException('Cette référence vient d\'être enregistrée par une autre saisie : rechargez la fiche.');
        }
        Cache::forget(self::CLE_CACHE);

        return $crees;
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  list<int>|null  $enseignantsDeLEtat
     * @return Collection<int, AvisVirement>
     */
    private function creer(?EtatPaie $etat, array $lignes, User $auteur, ?array $enseignantsDeLEtat, ?string $dateMin): Collection
    {
        $crees = collect();
        foreach ($lignes as $l) {
            $donnees = $this->valider($l, $enseignantsDeLEtat, $dateMin);
            $existant = AvisVirement::query()->enVigueur()->where('enseignant_id', $donnees['enseignant_id'])
                ->where('reference', $donnees['reference'])->lockForUpdate()->first();
            if ($existant !== null) {
                if ($existant->montant === $donnees['montant'] && $existant->date_valeur->toDateString() === $donnees['date_valeur']
                    && $existant->etat_id === $etat?->id) {
                    continue;
                }
                throw new InvalidArgumentException('La référence « '.$donnees['reference'].' » est déjà enregistrée pour cet enseignant : annulez l\'avis existant pour la corriger.');
            }
            $avis = AvisVirement::query()->create([...$donnees, 'etat_id' => $etat?->id, 'saisi_par' => $auteur->id]);
            activity(Journaux::PAIE)->causedBy($auteur)->performedOn($avis)->event('avis_virement_enregistre')
                ->withProperties(['etat' => $etat?->numero, 'enseignant_id' => $avis->enseignant_id, 'montant' => $avis->montant,
                    'date_valeur' => $donnees['date_valeur'], 'reference' => $avis->reference, 'source' => $avis->source])
                ->log('Avis de virement enregistré');
            $crees->push($avis);
        }

        return $crees;
    }

    public function annuler(AvisVirement $avis, string $motif, User $auteur): void
    {
        abort_unless(self::peutSaisir($auteur), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < self::MOTIF_MIN) {
            throw new InvalidArgumentException('Le motif de l\'annulation compte au moins '.self::MOTIF_MIN.' caractères.');
        }
        DB::transaction(function () use ($avis, $motif, $auteur): void {
            $a = AvisVirement::query()->whereKey($avis->id)->lockForUpdate()->firstOrFail();
            if ($a->estAnnule()) {
                throw new InvalidArgumentException('Cet avis est déjà annulé.');
            }
            $a->forceFill(['annule_at' => now(), 'annule_par' => $auteur->id, 'motif_annulation' => mb_substr($motif, 0, 500)])->save();
            activity(Journaux::PAIE)->causedBy($auteur)->performedOn($a)->event('avis_virement_annule')
                ->withProperties(['etat_id' => $a->etat_id, 'enseignant_id' => $a->enseignant_id, 'montant' => $a->montant, 'reference' => $a->reference, 'motif' => $motif])
                ->log('Avis de virement annulé');
        });
        Cache::forget(self::CLE_CACHE);
    }

    /**
     * Rapprochement d'un état, une ligne par enseignant (dus et virés).
     *
     * @return list<array{enseignant_id: int, enseignant: string, net_du: int, vire: int, ecart: int, situation: string, references: string}>
     */
    public function rapprochement(EtatPaie $etat): array
    {
        return $this->calculer([$etat->id])[$etat->id] ?? [];
    }

    /**
     * Bilan des états donnés, pour la liste : « complet », « ecarts » ou « a_faire » ; absent si l'état ne se
     * rapproche pas. Deux requêtes groupées, quel que soit le nombre d'états.
     *
     * @param  iterable<EtatPaie>  $etats
     * @return array<int, string> etat_id => bilan
     */
    public function bilans(iterable $etats): array
    {
        // foreach et non collect() : la liste passe une page (paginateur), que collect() réduirait à son tableau.
        $ids = [];
        foreach ($etats as $e) {
            if (in_array($e->statut, self::ETATS_RAPPROCHABLES, true)) {
                $ids[] = $e->id;
            }
        }
        $bilans = [];
        foreach ($this->calculer($ids) as $id => $lignes) {
            $situations = array_column($lignes, 'situation');
            $bilans[$id] = match (true) {
                $situations !== [] && array_diff($situations, [self::RAPPROCHE]) === [] => 'complet',
                array_intersect($situations, [self::PARTIEL, self::EN_TROP]) !== [] => 'ecarts',
                default => 'a_faire',
            };
        }

        return $bilans;
    }

    public function bilanEtat(EtatPaie $etat): ?string
    {
        return $this->bilans([$etat])[$etat->id] ?? null;
    }

    /**
     * Contrôle PAI_RAPPROCHEMENT : virés partiels, en trop, sans état, et non virés d'un état payé.
     *
     * @return list<array{etat_id: ?int, etat: ?string, etat_uuid: ?string, enseignant: string, net_du: int, vire: int, ecart: int, situation: string, references: string}>
     */
    public function anomalies(): array
    {
        $etats = EtatPaie::query()->whereIn('statut', self::ETATS_RAPPROCHABLES)->orderBy('periode_du')->get(['id', 'numero', 'uuid', 'statut'])->keyBy('id');
        $parEtat = $this->calculer($etats->keys()->all());
        $anomalies = [];
        foreach ($etats as $id => $etat) {
            foreach ($parEtat[$id] ?? [] as $r) {
                $anomalie = in_array($r['situation'], [self::PARTIEL, self::EN_TROP], true)
                    || ($r['situation'] === self::NON_VIRE && $etat->statut === EtatPaie::PAYE);
                if ($anomalie) {
                    $anomalies[] = [...$r, 'etat_id' => $etat->id, 'etat' => $etat->numero, 'etat_uuid' => $etat->uuid];
                }
            }
        }
        $sansEtat = AvisVirement::query()->enVigueur()->whereNull('etat_id')->with('enseignant:id,name')->orderBy('date_valeur')->get();
        foreach ($sansEtat as $a) {
            $anomalies[] = ['etat_id' => null, 'etat' => null, 'etat_uuid' => null, 'enseignant_id' => $a->enseignant_id,
                'enseignant' => (string) $a->enseignant?->name, 'net_du' => 0, 'vire' => $a->montant, 'ecart' => $a->montant,
                'situation' => self::SANS_ETAT, 'references' => $a->reference];
        }

        return $anomalies;
    }

    /** Nombre d'anomalies pour la pastille du menu : en cache une minute, vidé à chaque saisie ou annulation. */
    public function nombreAnomalies(): int
    {
        return (int) Cache::remember(self::CLE_CACHE, 60, fn (): int => count($this->anomalies()));
    }

    /**
     * Dus et virés des états donnés, en requêtes groupées (lignes, avis, noms).
     *
     * @param  list<int>  $etatIds
     * @return array<int, list<array{enseignant_id: int, enseignant: string, net_du: int, vire: int, ecart: int, situation: string, references: string}>>
     */
    private function calculer(array $etatIds): array
    {
        if ($etatIds === []) {
            return [];
        }
        $dus = LigneEtatPaie::query()->whereIn('etat_id', $etatIds)->groupBy('etat_id', 'enseignant_id')
            ->selectRaw('etat_id, enseignant_id, sum(montant_net) as net')->get()
            ->groupBy('etat_id')->map(fn (Collection $c): Collection => $c->pluck('net', 'enseignant_id'));
        $avis = AvisVirement::query()->enVigueur()->whereIn('etat_id', $etatIds)->orderBy('date_valeur')
            ->get(['etat_id', 'enseignant_id', 'montant', 'reference'])->groupBy('etat_id');
        $noms = User::query()->whereIn('id', $dus->flatMap(fn (Collection $c) => $c->keys())->merge($avis->flatten(1)->pluck('enseignant_id'))->unique())
            ->pluck('name', 'id');

        $resultat = [];
        foreach ($etatIds as $etatId) {
            $dusEtat = $dus->get($etatId, collect());
            $avisEtat = $avis->get($etatId, collect())->groupBy('enseignant_id');
            $resultat[$etatId] = $dusEtat->keys()->merge($avisEtat->keys())->unique()->map(function ($id) use ($dusEtat, $avisEtat, $noms): array {
                $du = (int) ($dusEtat[$id] ?? 0);
                $siens = $avisEtat->get($id, collect());
                $vire = (int) $siens->sum('montant');

                return [
                    'enseignant_id' => (int) $id,
                    'enseignant' => (string) ($noms[$id] ?? '—'),
                    'net_du' => $du,
                    'vire' => $vire,
                    'ecart' => $vire - $du,
                    'situation' => self::situation($du, $siens->isNotEmpty() ? $vire : null),
                    'references' => $siens->pluck('reference')->implode(', '),
                ];
            })->sortBy('enseignant')->values()->all();
        }

        return $resultat;
    }

    /**
     * Enseignants qui peuvent recevoir un virement : rôle enseignant, ou au moins une ligne d'état de paie.
     * Ni candidat, ni auditeur, ni agent sans lien avec la paie.
     *
     * @return Builder<User>
     */
    public static function enseignants(): Builder
    {
        return User::query()->where(fn (Builder $q) => $q->role('enseignant')
            ->orWhereIn('id', LigneEtatPaie::query()->select('enseignant_id')));
    }

    private static function situation(int $du, ?int $vire): string
    {
        return match (true) {
            $vire === null => self::NON_VIRE,
            $vire === $du => self::RAPPROCHE,
            $vire < $du => self::PARTIEL,
            default => self::EN_TROP,
        };
    }

    /**
     * @param  array<string, mixed>  $l
     * @param  list<int>|null  $enseignantsDeLEtat
     * @return array{enseignant_id: int, montant: int, date_valeur: string, reference: string, source: string}
     */
    private function valider(array $l, ?array $enseignantsDeLEtat, ?string $dateMin): array
    {
        $enseignant = (int) ($l['enseignant_id'] ?? 0);
        $montant = filter_var($l['montant'] ?? null, FILTER_VALIDATE_INT);
        $reference = trim((string) ($l['reference'] ?? ''));
        $source = (string) ($l['source'] ?? 'saisie');
        $date = (string) ($l['date_valeur'] ?? '');
        if ($enseignant <= 0 || ! self::enseignants()->whereKey($enseignant)->exists()) {
            throw new InvalidArgumentException('Ce compte n\'est pas celui d\'un enseignant.');
        }
        if ($enseignantsDeLEtat !== null && ! in_array($enseignant, $enseignantsDeLEtat, true)) {
            throw new InvalidArgumentException('Cet enseignant n\'a aucune ligne dans l\'état : enregistrez un virement sans état.');
        }
        if ($montant === false || $montant <= 0 || $montant > self::MONTANT_MAX) {
            throw new InvalidArgumentException('Le montant viré est un nombre entier de FCFA, supérieur à zéro.');
        }
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw new InvalidArgumentException('La référence du virement est obligatoire (100 caractères au plus).');
        }
        if (! array_key_exists($source, AvisVirement::SOURCES)) {
            throw new InvalidArgumentException('Source du virement inconnue.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date > now('Africa/Douala')->toDateString()) {
            throw new InvalidArgumentException('La date de valeur est une date passée ou du jour.');
        }
        if ($dateMin !== null && $date < $dateMin) {
            throw new InvalidArgumentException('La date de valeur ne précède pas la prise en charge de l\'état.');
        }

        return ['enseignant_id' => $enseignant, 'montant' => $montant, 'date_valeur' => $date, 'reference' => $reference, 'source' => $source];
    }
}
