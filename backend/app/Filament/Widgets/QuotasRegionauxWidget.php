<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\RegionCameroun;
use App\Support\BelongsToModule;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Widget dashboard — quotas régionaux temps réel pour la campagne courante.
 *
 * Seuils couleur (cf. arbitrage G PR D ajusté) :
 * - Vert    : écart < 3 points
 * - Orange  : écart 3-7 points
 * - Rouge   : écart > 7 points
 *
 * Polling auto 60s + bouton refresh manuel (Livewire $this->dispatch).
 *
 * Visible aux comptes qui ont accès au module des admissions et gèrent les
 * dossiers (`update_candidature`).
 */
#[BelongsToModule('admissions')]
class QuotasRegionauxWidget extends Widget
{
    use ChecksModuleAccess;

    protected static string $view = 'filament.widgets.quotas-regionaux';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected static bool $isLazy = true;

    /** Polling 60s. */
    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        // Pour qui gère les dossiers : permission et non noms de rôles, pour
        // qu'un rôle créé depuis l'écran des rôles le voie aussi.
        return static::moduleAllows()
            && (auth()->user()?->can('update_candidature') ?? false);
    }

    /**
     * @return array{campagne: ?CampagneCandidature, total: int, rows: Collection}
     */
    public function getViewData(): array
    {
        $campagne = CampagneCandidature::currentlyOpen()->first();

        if ($campagne === null) {
            return [
                'campagne' => null,
                'total' => 0,
                'rows' => collect(),
            ];
        }

        $total = Candidature::where('campagne_id', $campagne->id)->count();

        $countByRegion = Candidature::query()
            ->where('campagne_id', $campagne->id)
            ->whereNotNull('region')
            ->selectRaw('region, COUNT(*) as cnt')
            ->groupBy('region')
            ->pluck('cnt', 'region');

        $rows = RegionCameroun::query()
            ->orderBy('order')
            ->get()
            ->map(function (RegionCameroun $region) use ($countByRegion, $total): array {
                $count = (int) ($countByRegion[$region->code] ?? 0);
                $real = $total > 0 ? ($count / $total) * 100 : 0;
                $target = $region->quota_admission !== null
                    ? (float) $region->quota_admission * 100
                    : null;

                $gap = $target !== null ? round(abs($real - $target), 1) : null;

                return [
                    'code' => $region->code,
                    'nom' => $region->nom,
                    'count' => $count,
                    'real_percent' => round($real, 1),
                    'target_percent' => $target !== null ? round($target, 1) : null,
                    'gap_points' => $gap,
                    'color' => match (true) {
                        $gap === null => 'gray',
                        $gap < 3 => 'success',
                        $gap < 7 => 'warning',
                        default => 'danger',
                    },
                ];
            });

        return [
            'campagne' => $campagne,
            'total' => $total,
            'rows' => $rows,
        ];
    }

    public function refresh(): void
    {
        // Méthode appelée par le bouton "Actualiser" — Livewire re-rend automatiquement.
    }
}
