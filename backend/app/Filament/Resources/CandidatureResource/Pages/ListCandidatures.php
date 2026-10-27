<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource\Pages;

use App\Filament\Resources\CandidatureResource;
use App\Models\Candidature;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCandidatures extends ListRecords
{
    protected static string $resource = CandidatureResource::class;

    protected function getHeaderActions(): array
    {
        // Pas de bouton "Nouvelle candidature" — la création est interdite
        // côté admin (cf. arbitrage D PR D : un dossier ne peut être créé
        // que par le candidat authentifié via /v1/applications/me).
        return [];
    }

    /**
     * Onglets au-dessus de la liste : un clic pour ne voir que les retenus
     * (ou les dossiers en examen, ou les non-retenus). Les dossiers retirés
     * sont exclus des onglets de décision. Les filtres du tableau
     * s'appliquent en plus de l'onglet choisi.
     */
    public function getTabs(): array
    {
        // Paramètre nommé `$query` : Filament injecte par nom, pas par type.
        $decision = fn (string $statut) => fn (Builder $query): Builder => $query
            ->where('statut', $statut)
            ->whereNull('withdrawn_at');
        $compter = fn (string $statut): int => CandidatureResource::getEloquentQuery()
            ->where('statut', $statut)
            ->whereNull('withdrawn_at')
            ->count();

        return [
            'tous' => Tab::make('Tous les dossiers')
                ->icon('heroicon-o-queue-list'),
            'retenus' => Tab::make('Retenus')
                ->icon('heroicon-o-check-badge')
                ->modifyQueryUsing($decision(Candidature::STATUT_ACCEPTE))
                ->badge(fn (): int => $compter(Candidature::STATUT_ACCEPTE))
                ->badgeColor('success'),
            'en_examen' => Tab::make('En examen')
                ->icon('heroicon-o-clock')
                ->modifyQueryUsing($decision(Candidature::STATUT_CANDIDAT))
                ->badge(fn (): int => $compter(Candidature::STATUT_CANDIDAT))
                ->badgeColor('info'),
            'non_retenus' => Tab::make('Non retenus')
                ->icon('heroicon-o-x-circle')
                ->modifyQueryUsing($decision(Candidature::STATUT_REFUSE))
                ->badge(fn (): int => $compter(Candidature::STATUT_REFUSE))
                ->badgeColor('gray'),
        ];
    }
}
