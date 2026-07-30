<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Suivi des dépôts de la campagne courante : en ligne vs physique.
 *
 * Le communiqué n° 90001121 impose la remise d'un dossier papier complet au
 * bureau de la scolarité avant la clôture. Une candidature soumise en ligne
 * sans dossier papier reçu n'est donc pas recevable : c'est cet écart que
 * l'écran doit rendre visible au jour le jour.
 */
class DepotsCampagneWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole([
            'super_admin', 'admin', 'admission_committee', 'receptionniste',
        ]) ?? false;
    }

    protected function getStats(): array
    {
        $campagne = CampagneCandidature::currentlyOpen()->first()
            ?? CampagneCandidature::query()->orderByDesc('opens_at')->first();

        if ($campagne === null) {
            return [
                Stat::make('Aucune campagne', '—')
                    ->description('Aucun dépôt à suivre')
                    ->color('gray'),
            ];
        }

        $base = Candidature::query()->forCampagne($campagne->id);

        $soumisesEnLigne = (clone $base)->soumises()->count();
        $deposesPhysiquement = (clone $base)->deposePhysiquement()->count();
        $enAttente = (clone $base)->enAttenteDepotPhysique()->count();
        $recusAujourdHui = (clone $base)->whereDate('depot_physique_at', today())->count();

        $taux = $soumisesEnLigne > 0
            ? round(($deposesPhysiquement / $soumisesEnLigne) * 100, 1)
            : 0.0;

        return [
            Stat::make('Candidatures déposées en ligne', (string) $soumisesEnLigne)
                ->description($campagne->nom)
                ->descriptionIcon('heroicon-m-computer-desktop')
                ->color('primary'),

            Stat::make('Dossiers déposés physiquement', (string) $deposesPhysiquement)
                ->description($recusAujourdHui > 0
                    ? "dont {$recusAujourdHui} réceptionné(s) aujourd'hui"
                    : 'Bureau de la scolarité — porte 231')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color('success'),

            Stat::make('En attente de dépôt physique', (string) $enAttente)
                ->description('Soumis en ligne, dossier papier non remis')
                ->descriptionIcon('heroicon-m-clock')
                ->color($enAttente === 0 ? 'success' : 'warning'),

            Stat::make('Taux de dépôt physique', $taux.'%')
                ->description("{$deposesPhysiquement} dossier(s) sur {$soumisesEnLigne} soumis en ligne")
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($taux >= 70 ? 'success' : ($taux >= 40 ? 'warning' : 'danger')),
        ];
    }
}
