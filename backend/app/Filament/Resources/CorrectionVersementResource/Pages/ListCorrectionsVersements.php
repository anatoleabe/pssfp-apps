<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorrectionVersementResource\Pages;

use App\Filament\Resources\CorrectionVersementResource;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\VersementsExcel;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListCorrectionsVersements extends ListRecords
{
    protected static string $resource = CorrectionVersementResource::class;

    public function getSubheading(): ?string
    {
        $n = CorrectionVersementResource::demandesEnCours()->count();

        return ($n === 0 ? 'Aucune demande de correction en attente.' : $n.' demande(s) de correction en attente de décision, dans l\'écran des demandes.')
            .' Rien n\'est supprimé : chaque pièce relie le versement annulé, son remplaçant et leurs reçus.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exporter')->label('Exporter (Excel)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (): bool => EspaceFinances::peutExporter(auth()->user()))
                ->action(function (): ?StreamedResponse {
                    abort_unless(EspaceFinances::peutExporter(auth()->user()), 403);
                    $requete = $this->getFilteredSortedTableQuery();
                    if ((clone $requete)->count() > VersementsExcel::plafond()) {
                        Notification::make()->warning()->title('Export trop volumineux')->body('Affinez les filtres (période).')->send();

                        return null;
                    }
                    EspaceFinances::journaliserExport(auth()->user(), 'corrections', [
                        'filtres' => collect($this->tableFilters ?? [])->filter(fn ($v): bool => filled(array_filter((array) $v, fn ($x): bool => filled($x))))->all(),
                        'recherche' => filled($this->tableSearch),
                    ], (clone $requete)->count());
                    $contenu = app(VersementsExcel::class)->corrections($requete->with(VersementsExcel::RELATIONS_CORRECTIONS)->lazy(VersementsExcel::MORCEAU));

                    return response()->streamDownload(function () use ($contenu): void {
                        echo $contenu;
                    }, 'corrections-versements-'.now('Africa/Douala')->format('Y-m-d').'.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
                }),
        ];
    }
}
