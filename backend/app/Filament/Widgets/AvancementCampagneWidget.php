<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Services\CandidatureService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Widget compagnon (ajout 2 PR D) — vue d'ensemble campagne courante.
 *
 * Stats :
 * - Total dossiers soumis (statut != postulant)
 * - Décomposition postulant / candidat / accepté / refusé
 * - Ventilation des brouillons par cause de blocage (prêt / photo / autre),
 *   ajoutée en septembre 2026 : le seul compteur « postulant » ne permettait
 *   pas de distinguer un dossier abandonné d'un dossier complet en attente
 *   d'un simple clic. La liste nominative correspondante est accessible via
 *   le filtre « Brouillons à relancer » de CandidatureResource.
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

        // Pourquoi les brouillons n'avancent pas. Sans cette ventilation, le
        // compteur « postulant » ne dit pas si ces dossiers sont abandonnés ou
        // simplement à relancer d'un appel — la distinction change tout à
        // l'approche de la clôture.
        $blocages = app(CandidatureService::class)->classifyDraftsForCampagne($campagne->id);
        $prets = count($blocages[CandidatureService::DRAFT_READY]);
        $photoSeule = count($blocages[CandidatureService::DRAFT_PHOTO_ONLY]);
        $autresManques = count($blocages[CandidatureService::DRAFT_OTHER]);

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

            Stat::make('Prêts à soumettre', (string) $prets)
                ->description($prets > 0
                    ? 'Dossiers complets, en attente du clic du candidat — à relancer'
                    : 'Aucun dossier complet en attente')
                ->descriptionIcon('heroicon-m-phone-arrow-up-right')
                ->color($prets > 0 ? 'warning' : 'success'),

            Stat::make('Bloqués par la photo', (string) $photoSeule)
                ->description($photoSeule > 0
                    ? 'La photo d\'identité est le seul élément manquant'
                    : 'Aucun dossier bloqué sur la photo')
                ->descriptionIcon('heroicon-m-camera')
                ->color($photoSeule > 0 ? 'warning' : 'success'),

            Stat::make('Autres champs manquants', (string) $autresManques)
                ->description('Brouillons incomplets au-delà de la photo')
                ->descriptionIcon('heroicon-m-pencil-square')
                ->color($autresManques > 0 ? 'info' : 'success'),

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
