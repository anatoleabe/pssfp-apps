<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\ActiviteAnimation;
use App\Models\ProgrammationEnseignant;
use App\Models\SyntheseAnimation;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Journaux;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Synthèses de l'animation en ligne des cours (spec lot 13b-2 ; D25 ; port de synthese_animation, v_synthese_animation
 * et reprise/27_import_whatsapp.py). Prime par cours = montant du barème × coefficient (prorata ou seuil).
 */
final class SynthesesAnimation
{
    public const CLE_MODE = 'lmd.mode_prime_animation';

    public const CLE_SEUIL = 'lmd.seuil_prime_animation_pct';

    public const PRORATA = 'prorata';

    public const SEUIL = 'seuil';

    public const MODES = [
        self::PRORATA => 'Au prorata du taux de réalisation (D25)',
        self::SEUIL => 'Prime entière si le taux atteint le seuil, rien sinon',
    ];

    /** @var array{0: string, 1: float}|null Mode et seuil lus une fois par instance. */
    private ?array $reglage = null;

    public function __construct(private readonly ExportWhatsApp $export) {}

    /**
     * Crée la synthèse d'un enseignant sur un cours à partir de sa programmation.
     *
     * @param  array<string, mixed>  $d  periode_du, periode_au, groupe_whatsapp, activites (famille, type_activite, attendu, realise)
     */
    public function creer(ProgrammationEnseignant $prog, array $d, User $auteur): SyntheseAnimation
    {
        abort_unless($auteur->can(PermissionsLmd::POINTAGE), 403);
        $this->pasLaSienne($prog->enseignant_id, $auteur);
        if (! in_array($prog->statut, EtatsPaie::PROGRAMMATIONS_PAYABLES, true)) {
            throw new InvalidArgumentException('La programmation doit être validée, notifiée ou acceptée.');
        }
        $prog->loadMissing('quota.ecue.maquetteUe.maquette');
        $ecue = $prog->quota->ecue;
        $annee = (string) $ecue->maquetteUe->maquette->annee_academique;
        if (SyntheseAnimation::query()->where('annee_academique', $annee)->where('enseignant_id', $prog->enseignant_id)->where('maquette_ecue_id', $ecue->id)->exists()) {
            throw new InvalidArgumentException('La synthèse de cet enseignant sur ce cours existe déjà pour '.$annee.' : une prime par cours.');
        }

        try {
            return DB::transaction(function () use ($prog, $ecue, $annee, $d, $auteur): SyntheseAnimation {
                $s = (new SyntheseAnimation)->forceFill([
                    'annee_academique' => $annee, 'enseignant_id' => $prog->enseignant_id, 'maquette_ecue_id' => $ecue->id,
                    'programmation_id' => $prog->id, 'cree_par' => $auteur->id,
                ]);
                $this->remplir($s, $d);

                return $s->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('La synthèse de cet enseignant sur ce cours vient d\'être créée par un autre agent.');
        }
    }

    /** Période proposée : celle de la programmation, sinon celle de la période de cours. @return array{0: ?string, 1: ?string} */
    public function periodeProposee(ProgrammationEnseignant $prog): array
    {
        $prog->loadMissing('quota.periode');

        return [
            ($prog->debut_prevu ?? $prog->quota?->periode?->debut_prevu)?->format('Y-m-d'),
            ($prog->fin_prevue ?? $prog->quota?->periode?->fin_prevue)?->format('Y-m-d'),
        ];
    }

    /** @param  array<string, mixed>  $d */
    public function modifier(SyntheseAnimation $s, array $d, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::POINTAGE), 403);
        DB::transaction(function () use ($s, $d, $auteur): void {
            $l = $this->brouillon($s, $auteur);
            $avant = $this->instantane($l);
            $this->remplir($l, $d);
            $apres = $this->instantane($l);
            if ($avant !== $apres) {
                activity(Journaux::PAIE)->causedBy($auteur)->performedOn($l)->event('synthese_modifiee')
                    ->withProperties(['avant' => $avant, 'apres' => $apres])->log('Synthèse d\'animation modifiée');
            }
        });
    }

    /**
     * Compte les réalisations de l'enseignant dans l'export WhatsApp du groupe du cours. Seules les activités déjà
     * présentes dans la synthèse sont mises à jour. Le fichier et le nom saisi ne sont pas conservés.
     *
     * @return array{comptes: array<string, int>, lignes: int, absentes: list<string>}
     */
    public function importer(SyntheseAnimation $s, string $chemin, string $nomFichier, #[\SensitiveParameter] string $auteurGroupe, User $auteur): array
    {
        abort_unless($auteur->can(PermissionsLmd::POINTAGE), 403);
        $lu = $this->export->lire($chemin);

        return DB::transaction(function () use ($s, $lu, $nomFichier, $auteurGroupe, $auteur): array {
            $s = $this->brouillon($s, $auteur);
            $r = $this->export->compter($lu['texte'], $auteurGroupe, $s->periode_du, $s->periode_au);
            $absentes = [];
            foreach ($r['comptes'] as $type => $realise) {
                $n = $s->activites()->where('type_activite', $type)->update(['realise' => $realise, 'updated_at' => now()]);
                if ($n === 0) {
                    $absentes[] = $type;
                }
            }
            $nom = mb_substr(basename(str_replace('\\', '/', $nomFichier)), 0, 120);
            $s->forceFill([
                'source' => 'export_whatsapp', 'justificatif' => mb_substr('export WhatsApp '.$nom.' sha256='.$lu['empreinte'], 0, 255),
                'importe_par' => $auteur->id, 'importe_at' => now(),
            ])->save();
            activity(Journaux::PAIE)->causedBy($auteur)->performedOn($s)->event('export_whatsapp_importe')
                ->withProperties(['comptes' => $r['comptes'], 'lignes' => $r['lignes'], 'sha256' => $lu['empreinte']])->log('Export WhatsApp importé');

            return [...$r, 'absentes' => $absentes];
        });
    }

    /** La validation porte sur la version affichée au valideur (empreinte des activités et de la période). */
    public function valider(SyntheseAnimation $s, User $auteur, ?string $empreinte = null): void
    {
        abort_unless($auteur->can(PermissionsLmd::POINTAGE), 403);
        DB::transaction(function () use ($s, $auteur, $empreinte): void {
            $l = $this->brouillon($s, $auteur);
            if ($empreinte !== null && ! hash_equals($this->empreinte($l), $empreinte)) {
                throw new InvalidArgumentException('La synthèse a changé depuis son affichage : rechargez la page et contrôlez-la de nouveau.');
            }
            if (! in_array($l->programmation?->statut, EtatsPaie::PROGRAMMATIONS_PAYABLES, true)) {
                throw new InvalidArgumentException('La programmation de l\'enseignant sur ce cours n\'est plus active (déclinée ou annulée) : la prime n\'est pas due.');
            }
            if ($l->activites()->doesntExist()) {
                throw new InvalidArgumentException('Aucune activité : indiquez les activités attendues et réalisées.');
            }
            $l->forceFill(['statut' => SyntheseAnimation::VALIDE, 'valide_par' => $auteur->id, 'valide_at' => now(), 'motif_rejet' => null])->save();
        });
    }

    public function peutValider(SyntheseAnimation $s, User $auteur): bool
    {
        return $s->statut === SyntheseAnimation::BROUILLON && $s->enseignant_id !== $auteur->id && $auteur->can(PermissionsLmd::POINTAGE);
    }

    public function rejeter(SyntheseAnimation $s, string $motif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::POINTAGE), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new InvalidArgumentException('Le rejet exige un motif.');
        }
        DB::transaction(function () use ($s, $motif, $auteur): void {
            $this->brouillon($s, $auteur)->forceFill(['statut' => SyntheseAnimation::REJETE, 'motif_rejet' => mb_substr($motif, 0, 500)])->save();
        });
    }

    /** Une synthèse validée ou rejetée, pas encore payée, repasse en brouillon (motif journalisé). */
    public function rouvrir(SyntheseAnimation $s, string $motif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::POINTAGE), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new InvalidArgumentException('La réouverture exige un motif.');
        }
        DB::transaction(function () use ($s, $motif, $auteur): void {
            $l = SyntheseAnimation::query()->whereKey($s->id)->lockForUpdate()->firstOrFail();
            $this->pasLaSienne($l->enseignant_id, $auteur);
            if ($l->estPayee()) {
                throw new InvalidArgumentException('Cette prime est portée sur un état de paie : elle ne se rouvre pas.');
            }
            if ($l->statut === SyntheseAnimation::BROUILLON) {
                throw new InvalidArgumentException('La synthèse est déjà en brouillon.');
            }
            $l->forceFill(['statut' => SyntheseAnimation::BROUILLON, 'valide_par' => null, 'valide_at' => null, 'motif_rejet' => null])->save();
            activity(Journaux::PAIE)->causedBy($auteur)->performedOn($l)->event('synthese_rouverte')->withProperties(['motif' => mb_substr($motif, 0, 500)])->log('Synthèse d\'animation rouverte');
        });
    }

    // ---------------------------------------------------------------- calcul

    /**
     * Taux de réalisation (en %) : chaque activité compte au plus pour ce qui est attendu.
     *
     * @return array{contribution: ?float, animation: ?float, global: ?float}
     */
    public function taux(SyntheseAnimation $s): array
    {
        $a = $s->relationLoaded('activites') ? $s->activites : $s->activites()->get();
        $t = function ($lot): ?float {
            $attendu = $lot->sum('attendu');

            return $attendu > 0 ? round(100 * $lot->sum(fn (ActiviteAnimation $x): int => min($x->realise, $x->attendu)) / $attendu, 1) : null;
        };

        return [
            'contribution' => $t($a->where('famille', ActiviteAnimation::CONTRIBUTION)),
            'animation' => $t($a->where('famille', ActiviteAnimation::ANIMATION)),
            'global' => $t($a),
        ];
    }

    /** Part de la prime due : le taux arrondi à 4 décimales (prorata), ou 1 / 0 selon le seuil. */
    public function coefficient(SyntheseAnimation $s): float
    {
        $a = $s->relationLoaded('activites') ? $s->activites : $s->activites()->get();
        $attendu = $a->sum('attendu');
        if ($attendu <= 0) {
            return 0.0;
        }
        $realise = $a->sum(fn (ActiviteAnimation $x): int => min($x->realise, $x->attendu));
        if ($this->mode() === self::SEUIL) {
            return 100 * $realise / $attendu >= $this->seuil() ? 1.0 : 0.0;
        }

        return round($realise / $attendu, 4);
    }

    public function mode(): string
    {
        return $this->reglage()[0];
    }

    public function seuil(): float
    {
        return $this->reglage()[1];
    }

    /** Empreinte de la version affichée : période et activités. */
    public function empreinte(SyntheseAnimation $s): string
    {
        return hash('sha256', (string) json_encode($this->instantane($s)));
    }

    /** Ni l'enseignant concerné, même s'il a le droit de pointage, ni une synthèse figée. */
    public function peutModifier(SyntheseAnimation $s, User $auteur): bool
    {
        return $auteur->can(PermissionsLmd::POINTAGE) && $s->statut === SyntheseAnimation::BROUILLON && ! $s->estPayee() && $s->enseignant_id !== $auteur->id;
    }

    /** Le mode de calcul est un réglage d'administration. */
    public function definirMode(string $mode, float $seuil, User $auteur): void
    {
        abort_unless($auteur->can('settings.manage'), 403);
        if (! array_key_exists($mode, self::MODES)) {
            throw new InvalidArgumentException('Mode de calcul inconnu.');
        }
        if ($seuil < 1 || $seuil > 100) {
            throw new InvalidArgumentException('Le seuil est compris entre 1 et 100 %.');
        }
        AppSettings::set(self::CLE_MODE, $mode, $auteur->id);
        AppSettings::set(self::CLE_SEUIL, round($seuil, 1), $auteur->id);
        $this->reglage = null;
        activity(Journaux::PAIE)->causedBy($auteur)->event('mode_prime_animation_regle')->withProperties(['mode' => $mode, 'seuil' => $seuil])
            ->log('Mode de calcul de la prime d\'animation réglé');
    }

    // ---------------------------------------------------------------- interne

    /** @return array{0: string, 1: float} */
    private function reglage(): array
    {
        if ($this->reglage === null) {
            $m = (string) AppSettings::get(self::CLE_MODE, self::PRORATA);
            $this->reglage = [array_key_exists($m, self::MODES) ? $m : self::PRORATA, (float) AppSettings::get(self::CLE_SEUIL, 100)];
        }

        return $this->reglage;
    }

    /** Personne n'établit, ne modifie ni ne valide sa propre prime (séparation des tâches, comme les pointages). */
    private function pasLaSienne(int $enseignantId, User $auteur): void
    {
        if ($enseignantId === $auteur->id) {
            throw new InvalidArgumentException('C\'est votre propre synthèse : une autre personne de la Scolarité s\'en charge.');
        }
    }

    /** @return array{periode: string, activites: list<array<string, int|string>>} */
    private function instantane(SyntheseAnimation $s): array
    {
        return [
            'periode' => $s->periode_du?->format('Y-m-d').'/'.$s->periode_au?->format('Y-m-d'),
            'activites' => $s->activites()->get(['type_activite', 'famille', 'attendu', 'realise'])
                ->map(fn (ActiviteAnimation $a): array => $a->only(['type_activite', 'famille', 'attendu', 'realise']))->values()->all(),
        ];
    }

    /** Verrouille la synthèse et vérifie qu'elle est en brouillon, non payée, et pas celle de l'auteur. */
    private function brouillon(SyntheseAnimation $s, User $auteur): SyntheseAnimation
    {
        $l = SyntheseAnimation::query()->whereKey($s->id)->lockForUpdate()->firstOrFail();
        $this->pasLaSienne($l->enseignant_id, $auteur);
        if ($l->estPayee()) {
            throw new InvalidArgumentException('Cette prime est portée sur un état de paie : la synthèse est figée.');
        }
        if ($l->statut !== SyntheseAnimation::BROUILLON) {
            throw new InvalidArgumentException('Seule une synthèse en brouillon se modifie : rouvrez-la d\'abord.');
        }

        return $l;
    }

    /** @param  array<string, mixed>  $d */
    private function remplir(SyntheseAnimation $s, array $d): void
    {
        if (blank($d['periode_du'] ?? null) || blank($d['periode_au'] ?? null)) {
            throw new InvalidArgumentException('Indiquez la période du cours.');
        }
        $du = CarbonImmutable::parse((string) $d['periode_du']);
        $au = CarbonImmutable::parse((string) $d['periode_au']);
        if ($au->lt($du)) {
            throw new InvalidArgumentException('La fin de la période suit son début.');
        }
        $activites = $this->activites($d['activites'] ?? []);
        $s->forceFill([
            'periode_du' => $du->toDateString(), 'periode_au' => $au->toDateString(),
            'groupe_whatsapp' => blank($d['groupe_whatsapp'] ?? null) ? null : mb_substr(trim((string) $d['groupe_whatsapp']), 0, 150),
        ])->save();
        $s->activites()->whereNotIn('type_activite', array_keys($activites))->delete();
        foreach ($activites as $type => $a) {
            $ligne = $s->activites()->where('type_activite', $type)->first() ?? (new ActiviteAnimation)->forceFill(['synthese_id' => $s->id, 'type_activite' => $type]);
            $ligne->forceFill(['famille' => $a['famille'], 'attendu' => $a['attendu'], 'realise' => $a['realise']])->save();
        }
    }

    /**
     * @param  iterable<array<string, mixed>>  $lignes
     * @return array<string, array{famille: string, attendu: int, realise: int}>
     */
    private function activites(iterable $lignes): array
    {
        $o = [];
        foreach ($lignes as $l) {
            $type = trim((string) ($l['type_activite'] ?? ''));
            if ($type === '') {
                continue;
            }
            $type = mb_substr($type, 0, 80);
            $famille = (string) ($l['famille'] ?? '');
            if (! array_key_exists($famille, ActiviteAnimation::FAMILLES)) {
                throw new InvalidArgumentException('Famille inconnue pour « '.$type.' ».');
            }
            $attendu = (int) ($l['attendu'] ?? 0);
            $realise = (int) ($l['realise'] ?? 0);
            if ($attendu < 1 || $attendu > 10000 || $realise < 0 || $realise > 100000) {
                throw new InvalidArgumentException('« '.$type.' » : l\'attendu est d\'au moins 1 et le réalisé ne peut pas être négatif.');
            }
            if (isset($o[$type])) {
                throw new InvalidArgumentException('L\'activité « '.$type.' » figure deux fois.');
            }
            $o[$type] = ['famille' => $famille, 'attendu' => $attendu, 'realise' => $realise];
        }
        if ($o === []) {
            throw new InvalidArgumentException('Indiquez au moins une activité attendue.');
        }

        return $o;
    }
}
