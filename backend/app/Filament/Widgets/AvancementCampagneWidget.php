<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Widget compagnon (ajout 2 PR D) — vue d'ensemble campagne courante.
 *
 * Stats :
 * - Total dossiers soumis (statut != postulant)
 * - Décomposition postulant / candidat / accepté / refusé
 * - Taux frais payés
 * - Compte à rebours date de clôture (couleur conditionnelle)
 *
 * Visible à tout user qui peut accéder au panel admin.
 */
class AvancementCampagneWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole([
            'super_admin', 'admin', 'admission_committee', 'librarian', 'editor',
        ]) ?? false;
    }

    protected function getStats(): array
    {
        $campagne = CampagneCandidature::currentlyOpen()->first();

        if ($campagne === null) {
            return [
                Stat::make('Aucune campagne ouverte', '—')
                    ->description('Aucun dossier en cours de réception')
                    ->color('gray'),
            ];
        }

        $byStatut = Candidature::query()
            ->where('campagne_id', $campagne->id)
            ->selectRaw('statut, COUNT(*) as cnt')
            ->groupBy('statut')
            ->pluck('cnt', 'statut');

        $total = (int) $byStatut->sum();
        $postulants = (int) ($byStatut['postulant'] ?? 0);
        $candidats = (int) ($byStatut['candidat'] ?? 0);
        $acceptes = (int) ($byStatut['accepte'] ?? 0);
        $refuses = (int) ($byStatut['refuse'] ?? 0);

        $fraisPayes = Candidature::query()
            ->where('campagne_id', $campagne->id)
            ->where('frais_paye', true)
            ->count();

        $tauxFrais = $total > 0 ? round(($fraisPayes / $total) * 100, 1) : 0.0;

        $now = now();
        $remainingDays = $campagne->closes_at !== null
            ? (int) max(0, $now->diffInDays($campagne->closes_at, false))
            : null;

        $countdownColor = match (true) {
            $remainingDays === null => 'gray',
            $remainingDays > 14 => 'success',
            $remainingDays > 3 => 'warning',
            default => 'danger',
        };

        return [
            Stat::make('Total dossiers', (string) $total)
                ->description('Soumis et en attente combinés')
                ->descriptionIcon('heroicon-m-document-duplicate')
                ->color('primary'),

            Stat::make('Décomposition statut',
                "P: {$postulants}  ·  C: {$candidats}  ·  A: {$acceptes}  ·  R: {$refuses}")
                ->description('Postulant / Candidat / Accepté / Refusé')
                ->color('info'),

            Stat::make('Taux frais payés', $tauxFrais.'%')
                ->description("{$fraisPayes} dossier(s) sur {$total}")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($tauxFrais >= 70 ? 'success' : ($tauxFrais >= 40 ? 'warning' : 'danger')),

            Stat::make('Clôture',
                $remainingDays !== null ? "J-{$remainingDays}" : '—')
                ->description($campagne->closes_at?->format('d/m/Y') ?? '—')
                ->descriptionIcon('heroicon-m-calendar')
                ->color($countdownColor),
        ];
    }
}
