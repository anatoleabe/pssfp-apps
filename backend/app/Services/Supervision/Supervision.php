<?php

declare(strict_types=1);

namespace App\Services\Supervision;

use App\Models\AnomalieSysteme;
use App\Models\User;
use App\Services\Supervision\Controles\DemAttente;
use App\Services\Supervision\Controles\PaieEtatsBloques;
use App\Services\Supervision\Controles\PaiRapprochement;
use App\Services\Supervision\Controles\ReqNonCotees;
use App\Services\Supervision\Controles\ReqRetard;
use App\Services\Supervision\Controles\SecComptes;
use App\Services\Supervision\Controles\SecConnexions;
use App\Services\Supervision\Controles\SecJournal;
use App\Services\Supervision\Controles\SecRevue;
use App\Services\Supervision\Controles\SecSeparation;
use App\Services\Supervision\Controles\SysControles;
use App\Services\Supervision\Controles\SysDestinataires;
use App\Support\Journaux;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Lot 14a (D30) : contrôle interne. Exécute les contrôles et tient le cycle de vie de leurs anomalies
 * (port de `executer_controle`, sql/19_schema_supervision.sql).
 */
final class Supervision
{
    public const CONSULTER = 'supervision.consulter';

    public const TRAITER = 'supervision.traiter';

    public const MOTIF_MIN = 10;

    public const FUSEAU = 'Africa/Douala';

    /** Catalogue, dans l'ordre d'exécution ; SYS_CONTROLES en dernier (il lit les erreurs des autres). */
    public const CATALOGUE = [
        PaiRapprochement::class, PaieEtatsBloques::class, ReqRetard::class, ReqNonCotees::class, DemAttente::class,
        SecSeparation::class, SecComptes::class, SecConnexions::class, SecJournal::class, SecRevue::class, SysDestinataires::class, SysControles::class,
    ];

    public static function peutConsulter(?User $user): bool
    {
        return (bool) $user?->can(self::CONSULTER);
    }

    public static function peutTraiter(?User $user): bool
    {
        return (bool) $user?->can(self::TRAITER);
    }

    /** @return array<string, Controle> code => contrôle */
    public function controles(): array
    {
        $controles = [];
        foreach (self::CATALOGUE as $classe) {
            $c = app($classe);
            $controles[$c->code()] = $c;
        }

        return $controles;
    }

    /**
     * Exécute les contrôles (tous, ou ceux donnés). Un contrôle en erreur n'arrête pas les autres.
     *
     * @param  list<string>|null  $codes
     * @return array<string, array{trouves: int, nouveaux: int, corriges: int, erreur: ?string}>
     */
    public function executer(?array $codes = null): array
    {
        $bilan = [];
        foreach ($this->controles() as $code => $controle) {
            if ($codes !== null && ! in_array($code, $codes, true)) {
                continue;
            }
            $execution = DB::table('executions_controles')->insertGetId(['controle_code' => $code, 'debut' => now()]);
            try {
                // Point de sauvegarde : une requête en échec n'empoisonne pas une transaction englobante (l'erreur doit pouvoir s'enregistrer).
                $resultat = $this->appliquer($controle, DB::transaction(fn (): array => $controle->cas()));
                $erreur = null;
            } catch (Throwable $e) {
                $resultat = ['trouves' => 0, 'nouveaux' => 0, 'corriges' => 0];
                $erreur = mb_substr($e::class.' : '.$e->getMessage(), 0, 1000);
                Log::error('Contrôle interne en erreur', ['controle' => $code, 'exception' => $e]);
            }
            DB::table('executions_controles')->where('id', $execution)->update([...$resultat, 'fin' => now(), 'erreur' => $erreur]);
            $bilan[$code] = [...$resultat, 'erreur' => $erreur];
        }

        return $bilan;
    }

    public function prendreEnCharge(AnomalieSysteme $anomalie, User $auteur): void
    {
        abort_unless(self::peutTraiter($auteur), 403);
        DB::transaction(function () use ($anomalie, $auteur): void {
            $a = AnomalieSysteme::query()->whereKey($anomalie->id)->lockForUpdate()->firstOrFail();
            if ($a->statut !== AnomalieSysteme::OUVERTE) {
                throw new InvalidArgumentException('Seule une anomalie ouverte se prend en charge.');
            }
            $a->forceFill(['statut' => AnomalieSysteme::EN_TRAITEMENT, 'assigne_a' => $auteur->id])->save();
            activity(Journaux::SUPERVISION)->causedBy($auteur)->performedOn($a)->event('anomalie_prise_en_charge')
                ->withProperties(['controle' => $a->controle_code, 'cle' => $a->cle])->log('Anomalie prise en charge');
        });
    }

    public function justifier(AnomalieSysteme $anomalie, string $motif, User $auteur): void
    {
        abort_unless(self::peutTraiter($auteur), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < self::MOTIF_MIN) {
            throw new InvalidArgumentException('La justification compte au moins '.self::MOTIF_MIN.' caractères.');
        }
        DB::transaction(function () use ($anomalie, $motif, $auteur): void {
            $a = AnomalieSysteme::query()->whereKey($anomalie->id)->lockForUpdate()->firstOrFail();
            if (! in_array($a->statut, AnomalieSysteme::A_TRAITER, true)) {
                throw new InvalidArgumentException('Seule une anomalie ouverte ou en traitement se justifie.');
            }
            $a->forceFill(['statut' => AnomalieSysteme::JUSTIFIEE, 'justification' => mb_substr($motif, 0, 1000), 'traite_par' => $auteur->id, 'traite_le' => now()])->save();
            activity(Journaux::SUPERVISION)->causedBy($auteur)->performedOn($a)->event('anomalie_justifiee')
                ->withProperties(['controle' => $a->controle_code, 'cle' => $a->cle, 'motif' => $motif])->log('Anomalie justifiée (acceptée)');
        });
    }

    /** Pastille : anomalies à traiter dont l'échéance est passée. */
    public function nombreEnRetard(): int
    {
        return AnomalieSysteme::query()->aTraiter()->where('echeance', '<', CarbonImmutable::now(self::FUSEAU)->toDateString())->count();
    }

    /**
     * Tableau de bord par contrôle.
     *
     * @return list<array{code: string, libelle: string, domaine: string, criticite: string, ouvertes: int, en_retard: int, justifiees: int, corrigees_30j: int, delai_moyen: ?float, derniere_execution: ?string, erreur: ?string}>
     */
    public function tableau(): array
    {
        $aujourdhui = CarbonImmutable::now(self::FUSEAU)->toDateString();
        $depuis = now()->subDays(30);
        $lignes = [];
        $relances = app(Relances::class)->parControle();
        foreach ($this->controles() as $code => $c) {
            $base = AnomalieSysteme::query()->where('controle_code', $code);
            $corrigees = (clone $base)->where('statut', AnomalieSysteme::CORRIGEE)->where('traite_le', '>=', $depuis)->get(['premiere_detection', 'traite_le']);
            $derniere = DB::table('executions_controles')->where('controle_code', $code)->orderByDesc('id')->first(['fin', 'erreur']);
            $lignes[] = [
                'code' => $code, 'libelle' => $c->libelle(), 'domaine' => $c->domaine(), 'criticite' => $c->criticite(),
                'ouvertes' => (clone $base)->aTraiter()->count(),
                'en_retard' => (clone $base)->aTraiter()->where('echeance', '<', $aujourdhui)->count(),
                'justifiees' => (clone $base)->where('statut', AnomalieSysteme::JUSTIFIEE)->count(),
                'corrigees_30j' => $corrigees->count(),
                'delai_moyen' => $corrigees->isEmpty() ? null : round($corrigees->avg(fn ($a): float => $a->premiere_detection->diffInHours($a->traite_le) / 24), 1),
                'relances' => $relances[$code] ?? 0,
                'derniere_execution' => $derniere?->fin,
                'erreur' => $derniere?->erreur,
            ];
        }

        return $lignes;
    }

    /**
     * Ouvre les cas nouveaux, met à jour les présents, ferme les disparus (corrigés). Une justifiée reste justifiée.
     *
     * @param  array<string, array<string, scalar|null>>  $cas
     * @return array{trouves: int, nouveaux: int, corriges: int}
     */
    private function appliquer(Controle $controle, array $cas): array
    {
        return DB::transaction(function () use ($controle, $cas): array {
            $actives = AnomalieSysteme::query()->where('controle_code', $controle->code())->whereIn('statut', AnomalieSysteme::ACTIVES)
                ->lockForUpdate()->get()->keyBy('cle');
            $maintenant = now();
            $echeance = CarbonImmutable::now(self::FUSEAU)->addDays($controle->delaiJours())->toDateString();
            $nouveaux = 0;
            foreach ($cas as $cle => $detail) {
                $cle = mb_substr((string) $cle, 0, 255);
                $existante = $actives->get($cle);
                if ($existante !== null) {
                    $existante->forceFill(['derniere_detection' => $maintenant, 'detail' => $detail])->save();

                    continue;
                }
                AnomalieSysteme::query()->forceCreate(['controle_code' => $controle->code(), 'cle' => $cle, 'detail' => $detail,
                    'premiere_detection' => $maintenant, 'derniere_detection' => $maintenant, 'echeance' => $echeance, 'statut' => AnomalieSysteme::OUVERTE]);
                $nouveaux++;
            }
            $corriges = 0;
            foreach ($actives as $cle => $a) {
                if (! array_key_exists($cle, $cas)) {
                    $a->forceFill(['statut' => AnomalieSysteme::CORRIGEE, 'fermee_auto' => true, 'traite_le' => $maintenant])->save();
                    $corriges++;
                }
            }

            return ['trouves' => count($cas), 'nouveaux' => $nouveaux, 'corriges' => $corriges];
        });
    }
}
