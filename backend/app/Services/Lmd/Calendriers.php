<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\CalendrierAcademique;
use App\Models\DependanceEvenement;
use App\Models\EvenementCalendrier;
use App\Models\ModificationEvenement;
use App\Models\User;
use App\Support\CandidatureObjectStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Calendrier académique (spec lot 12a ; D18 à D21) : préparation, circuit de signature,
 * référence figée, réajustement motivé et journalisé, suivi d'exécution, versions.
 */
final class Calendriers
{
    public const DOSSIER = 'lmd/calendriers';

    public function __construct(private readonly ControlesCalendrier $controles, private readonly Actes $actes) {}

    public function creer(string $annee, string $debut, string $fin, User $auteur): CalendrierAcademique
    {
        abort_unless($auteur->can(PermissionsLmd::CALENDRIER_PREPARER), 403);
        if (! preg_match('/^(\d{4})-(\d{4})$/', $annee, $m) || (int) $m[2] !== (int) $m[1] + 1) {
            throw new InvalidArgumentException('Année académique attendue sous la forme 2026-2027.');
        }
        if (CalendrierAcademique::query()->where('annee_academique', $annee)->whereIn('statut', [CalendrierAcademique::BROUILLON, CalendrierAcademique::SOUMIS])->exists()) {
            throw new InvalidArgumentException('Une version de ce calendrier est déjà en préparation.');
        }
        $c = new CalendrierAcademique(['annee_academique' => $annee, 'date_debut' => $debut, 'date_fin' => $fin]);
        $c->forceFill(['version' => (int) CalendrierAcademique::query()->where('annee_academique', $annee)->max('version') + 1, 'prepare_par' => $auteur->id])->save();

        return $c;
    }

    /**
     * Crée ou modifie un événement. En brouillon : préparation libre. Calendrier signé ou publié :
     * réajustement réservé au responsable de la scolarité, motif obligatoire, journal (D20).
     *
     * @param  array<string, mixed>  $data
     */
    public function enregistrer(CalendrierAcademique $c, array $data, User $auteur, ?EvenementCalendrier $e = null, ?string $motif = null): EvenementCalendrier
    {
        $this->modifiable($c, $auteur);
        $debut = CarbonImmutable::parse((string) $data['debut_prevu']);
        $fin = CarbonImmutable::parse((string) ($data['fin_prevue'] ?? $data['debut_prevu']));
        if ($fin->lt($debut)) {
            throw new InvalidArgumentException('La fin doit suivre le début.');
        }
        if ($debut->lt($c->date_debut) || $fin->gt($c->date_fin)) {
            throw new InvalidArgumentException('Dates hors de l\'année académique ('.$c->date_debut->format('d/m/Y').' au '.$c->date_fin->format('d/m/Y').').');
        }
        if (! array_key_exists((string) $data['type'], EvenementCalendrier::TYPES)) {
            throw new InvalidArgumentException('Type d\'événement inconnu.');
        }
        $parent = empty($data['parent_id']) ? null : EvenementCalendrier::query()->find($data['parent_id']);
        if ($parent !== null && ($parent->calendrier_id !== $c->id || ! $parent->estPhase() || $parent->id === $e?->id)) {
            throw new InvalidArgumentException('Le parent d\'un événement est une phase du même calendrier.');
        }
        $dateChange = $e === null || ! $e->debut_prevu->equalTo($debut) || ! $e->fin_prevue->equalTo($fin);
        if ($c->estSigne() && $dateChange && ($data['type'] ?? null) !== 'phase' && mb_strlen(trim((string) $motif)) < 5) {
            throw new InvalidArgumentException('Calendrier signé : toute modification de date exige un motif.');
        }

        return DB::transaction(function () use ($c, $data, $auteur, $e, $motif, $debut, $fin, $dateChange): EvenementCalendrier {
            $ancien = $e === null ? null : [$e->debut_prevu, $e->fin_prevue];
            $e ??= new EvenementCalendrier;
            $e->fill([...array_intersect_key($data, array_flip($e->getFillable())), 'debut_prevu' => $debut->toDateString(), 'fin_prevue' => $fin->toDateString(),
                'parent_id' => $data['parent_id'] ?? null]);
            $e->calendrier_id = $c->id;
            $e->save();
            if ($c->estSigne() && $dateChange && ! $e->estPhase()) {
                $this->journaliser($e, $ancien[0] ?? null, $ancien[1] ?? null, $e->wasRecentlyCreated ? null : $e->statut, $e->statut, (string) $motif, $auteur);
                $this->actes->suivreEvenement($e);
            }
            $this->recalculerPhases($c);

            return $e;
        });
    }

    /** Suppression d'un événement : en brouillon seulement. */
    public function supprimer(EvenementCalendrier $e, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::CALENDRIER_PREPARER), 403);
        $c = $e->calendrier;
        if ($c->statut !== CalendrierAcademique::BROUILLON) {
            throw new InvalidArgumentException('Un événement ne se supprime qu\'en brouillon ; sur un calendrier signé, annulez-le avec un motif.');
        }
        DB::transaction(function () use ($e, $c): void {
            EvenementCalendrier::query()->where('parent_id', $e->id)->update(['parent_id' => null]);
            $e->delete();
            $this->recalculerPhases($c);
        });
    }

    /**
     * Exécution (D21) : statut, dates réelles, avancement. Report et annulation exigent un motif ;
     * sur un calendrier signé, tout changement de statut est journalisé.
     *
     * @param  array{statut: string, debut_reel?: ?string, fin_reelle?: ?string, avancement_pct?: ?int, motif?: ?string}  $data
     */
    public function executer(EvenementCalendrier $e, array $data, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::CALENDRIER_PREPARER), 403);
        $c = $e->calendrier;
        if (! $c->estSigne()) {
            throw new InvalidArgumentException('L\'exécution se suit sur un calendrier signé.');
        }
        if ($e->estPhase()) {
            throw new InvalidArgumentException('L\'avancement d\'une phase se déduit de ses événements.');
        }
        $statut = (string) $data['statut'];
        if (! array_key_exists($statut, EvenementCalendrier::STATUTS)) {
            throw new InvalidArgumentException('Statut inconnu.');
        }
        $motif = trim((string) ($data['motif'] ?? ''));
        if (in_array($statut, ['reporte', 'annule'], true) && mb_strlen($motif) < 5) {
            throw new InvalidArgumentException('Un report ou une annulation exige un motif.');
        }
        if ($statut === 'realise' && empty($data['fin_reelle'])) {
            $data['fin_reelle'] = now('Africa/Douala')->toDateString();
        }
        DB::transaction(function () use ($e, $data, $statut, $motif, $auteur): void {
            $ancien = $e->statut;
            $e->forceFill([
                'statut' => $statut,
                'debut_reel' => $data['debut_reel'] ?? $e->debut_reel?->toDateString() ?? ($statut !== 'prevu' ? $e->debut_prevu->toDateString() : null),
                'fin_reelle' => $data['fin_reelle'] ?? null,
                'avancement_pct' => $statut === 'realise' ? 100 : max(0, min(100, (int) ($data['avancement_pct'] ?? $e->avancement_pct))),
                'motif' => in_array($statut, ['reporte', 'annule'], true) ? mb_substr($motif, 0, 500) : null,
            ])->save();
            if ($ancien !== $statut) {
                $this->journaliser($e, $e->debut_prevu, $e->fin_prevue, $ancien, $statut, $motif !== '' ? $motif : 'Changement de statut', $auteur);
            }
            if ($statut === 'annule') {
                $this->actes->annulerPourEvenement($e);
            }
        });
    }

    /** Ajoute ou met à jour un lien saisi par la scolarité. */
    public function lier(EvenementCalendrier $p, EvenementCalendrier $s, string $type, int $min, ?int $max, bool $bloquant, User $auteur): DependanceEvenement
    {
        $this->modifiable($p->calendrier, $auteur);
        if ($p->calendrier_id !== $s->calendrier_id || $p->id === $s->id || $p->estPhase() || $s->estPhase()) {
            throw new InvalidArgumentException('Un lien relie deux événements distincts du même calendrier (hors phases).');
        }
        if (! array_key_exists($type, DependanceEvenement::TYPES) || ($max !== null && $max < $min)) {
            throw new InvalidArgumentException('Lien invalide : l\'écart maximal doit dépasser l\'écart minimal.');
        }

        return DependanceEvenement::query()->updateOrCreate(['predecesseur_id' => $p->id, 'successeur_id' => $s->id],
            ['type_lien' => $type, 'decalage_min_jours' => $min, 'decalage_max_jours' => $max, 'bloquant' => $bloquant, 'regle' => 'Lien saisi par la scolarité']);
    }

    public function soumettre(CalendrierAcademique $c, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::CALENDRIER_PREPARER), 403);
        if ($c->statut !== CalendrierAcademique::BROUILLON) {
            throw new InvalidArgumentException('Seul un calendrier en brouillon se soumet.');
        }
        $this->sansAnomalie($c, 'Soumission refusée');
        $c->forceFill(['statut' => CalendrierAcademique::SOUMIS, 'soumis_at' => now(), 'soumis_par' => $auteur->id, 'renvoi_motif' => null])->save();
    }

    public function renvoyer(CalendrierAcademique $c, string $motif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::CALENDRIER_SIGNER), 403);
        if ($c->statut !== CalendrierAcademique::SOUMIS || mb_strlen(trim($motif)) < 5) {
            throw new InvalidArgumentException('Seul un calendrier soumis se renvoie, avec un motif.');
        }
        $c->forceFill(['statut' => CalendrierAcademique::BROUILLON, 'renvoi_motif' => mb_substr(trim($motif), 0, 500)])->save();
    }

    /** Signature (D21) : pièce signée obligatoire, référence figée, version précédente remplacée, actes générés (D18). */
    public function signer(CalendrierAcademique $c, ?UploadedFile $piece, string $signataire, string $date, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::CALENDRIER_SIGNER), 403);
        if ($c->statut !== CalendrierAcademique::SOUMIS) {
            throw new InvalidArgumentException('Seul un calendrier soumis se signe.');
        }
        $this->sansAnomalie($c, 'Signature refusée');
        if ($piece === null || ! $piece->isValid() || $piece->getMimeType() !== 'application/pdf') {
            throw new InvalidArgumentException('Joignez le calendrier signé numérisé (PDF).');
        }
        if (trim($signataire) === '') {
            throw new InvalidArgumentException('Indiquez le signataire.');
        }
        $contenu = (string) file_get_contents($piece->getRealPath());
        $chemin = self::DOSSIER.'/'.Str::ulid().'.pdf';
        if (! Storage::disk(CandidatureObjectStorage::DISK)->put($chemin, $contenu)) {
            throw new InvalidArgumentException('La pièce n\'a pas pu être enregistrée : réessayez dans quelques minutes.');
        }
        DB::transaction(function () use ($c, $chemin, $contenu, $signataire, $date, $auteur): void {
            $this->figerReference($c);
            $remplaces = CalendrierAcademique::query()->where('annee_academique', $c->annee_academique)->where('id', '<>', $c->id)
                ->whereIn('statut', [CalendrierAcademique::SIGNE, CalendrierAcademique::PUBLIE])->get();
            foreach ($remplaces as $ancien) {
                $this->actes->reporterVersion($ancien, $c);
                $ancien->forceFill(['statut' => CalendrierAcademique::REMPLACE])->save();
            }
            $c->forceFill(['statut' => CalendrierAcademique::SIGNE, 'signataire' => mb_substr(trim($signataire), 0, 255), 'signe_le' => $date,
                'signe_par' => $auteur->id, 'piece_signee_path' => $chemin, 'piece_signee_sha256' => hash('sha256', $contenu)])->save();
            $this->actes->generer($c);
        });
    }

    public function publier(CalendrierAcademique $c, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::CALENDRIER_REAJUSTER) || $auteur->can(PermissionsLmd::CALENDRIER_SIGNER), 403);
        if ($c->statut !== CalendrierAcademique::SIGNE) {
            throw new InvalidArgumentException('Seul un calendrier signé se publie.');
        }
        $c->forceFill(['statut' => CalendrierAcademique::PUBLIE, 'publie_at' => now(), 'publie_par' => $auteur->id])->save();
    }

    /** Révision majeure : copie en brouillon des événements et des liens d'une version signée (D21). */
    public function nouvelleVersion(CalendrierAcademique $c, User $auteur): CalendrierAcademique
    {
        if (! $c->estSigne()) {
            throw new InvalidArgumentException('Une nouvelle version part d\'un calendrier signé ou publié.');
        }

        return DB::transaction(function () use ($c, $auteur): CalendrierAcademique {
            $copie = $this->creer($c->annee_academique, $c->date_debut->toDateString(), $c->date_fin->toDateString(), $auteur);
            $correspondance = [];
            $evenements = $c->evenements()->orderByRaw('parent_id IS NOT NULL')->orderBy('id')->get();
            foreach ($evenements as $e) {
                $n = $e->replicate(['uuid', 'debut_signe', 'fin_signe', 'debut_reel', 'fin_reelle', 'statut', 'motif', 'avancement_pct']);
                $n->forceFill(['calendrier_id' => $copie->id, 'parent_id' => $e->parent_id === null ? null : ($correspondance[$e->parent_id] ?? null)])->save();
                $correspondance[$e->id] = $n->id;
            }
            foreach (DependanceEvenement::query()->whereIn('predecesseur_id', array_keys($correspondance))->get() as $d) {
                DependanceEvenement::query()->create([...$d->only(['type_lien', 'decalage_min_jours', 'decalage_max_jours', 'regle', 'bloquant']),
                    'predecesseur_id' => $correspondance[$d->predecesseur_id], 'successeur_id' => $correspondance[$d->successeur_id]]);
            }

            return $copie;
        });
    }

    /** Calendrier publié en vigueur d'une année (le plus récent). */
    public function publie(string $annee): ?CalendrierAcademique
    {
        return CalendrierAcademique::query()->where('annee_academique', $annee)->where('statut', CalendrierAcademique::PUBLIE)->orderByDesc('version')->first();
    }

    /** Dates de référence = dates prévues au moment de la signature. */
    public function figerReference(CalendrierAcademique $c): void
    {
        $this->recalculerPhases($c);
        $c->evenements()->whereNull('debut_signe')->update(['debut_signe' => DB::raw('debut_prevu'), 'fin_signe' => DB::raw('fin_prevue')]);
        $c->forceFill(['reference_figee_at' => $c->reference_figee_at ?? now()])->save();
    }

    /** Dates d'une phase = enveloppe de ses événements. */
    public function recalculerPhases(CalendrierAcademique $c): void
    {
        foreach ($c->evenements()->where('type', 'phase')->get() as $ph) {
            $enfants = EvenementCalendrier::query()->where('parent_id', $ph->id);
            $debut = (clone $enfants)->min('debut_prevu');
            if ($debut !== null) {
                $ph->forceFill(['debut_prevu' => $debut, 'fin_prevue' => (clone $enfants)->max('fin_prevue')])->save();
            }
        }
    }

    private function modifiable(CalendrierAcademique $c, User $auteur): void
    {
        if ($c->statut === CalendrierAcademique::REMPLACE) {
            throw new InvalidArgumentException('Version remplacée : elle reste consultable telle que signée.');
        }
        if ($c->statut === CalendrierAcademique::SOUMIS) {
            throw new InvalidArgumentException('Calendrier soumis à la signature : il doit être renvoyé en brouillon pour être modifié.');
        }
        abort_unless($auteur->can($c->estSigne() ? PermissionsLmd::CALENDRIER_REAJUSTER : PermissionsLmd::CALENDRIER_PREPARER), 403);
    }

    private function sansAnomalie(CalendrierAcademique $c, string $prefixe): void
    {
        $anomalies = $this->controles->anomaliesBloquantes($c);
        if ($anomalies !== []) {
            throw new InvalidArgumentException($prefixe.' : '.count($anomalies).' anomalie(s) bloquante(s). '
                .implode(' ; ', array_map(fn (array $a): string => $a['libelle'].' ('.$a['detail'].')', array_slice($anomalies, 0, 5))));
        }
    }

    private function journaliser(EvenementCalendrier $e, mixed $ancienDebut, mixed $ancienneFin, ?string $ancienStatut, ?string $nouveauStatut, string $motif, User $auteur): void
    {
        ModificationEvenement::query()->create([
            'evenement_id' => $e->id, 'ancien_debut' => $ancienDebut, 'ancienne_fin' => $ancienneFin, 'ancien_statut' => $ancienStatut,
            'nouveau_debut' => $e->debut_prevu, 'nouvelle_fin' => $e->fin_prevue, 'nouveau_statut' => $nouveauStatut,
            'motif' => mb_substr(trim($motif), 0, 500), 'auteur_id' => $auteur->id,
        ]);
    }
}
