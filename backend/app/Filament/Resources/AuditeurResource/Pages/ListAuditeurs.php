<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource\Pages;

use App\Filament\Resources\AuditeurResource;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\SituationFinanciereExcel;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAuditeurs extends ListRecords
{
    protected static string $resource = AuditeurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exporterSituation')
                ->label('Exporter la situation (Excel)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_EXPORT))
                ->action(function (): StreamedResponse {
                    abort_unless(auth()->user()?->can(RegistreVersements::PERMISSION_EXPORT), 403);
                    // Mêmes filtres et recherche que la liste affichée.
                    $auditeurs = $this->getFilteredSortedTableQuery()->get();
                    activity('scolarite')->causedBy(auth()->user())->event('situation_exportee')
                        ->withProperties(['lignes' => $auditeurs->count()])->log('Situation financière exportée');
                    $contenu = app(SituationFinanciereExcel::class)->generer($auditeurs);

                    return response()->streamDownload(function () use ($contenu): void {
                        echo $contenu;
                    }, 'situation-auditeurs-'.now('Africa/Douala')->format('Y-m-d').'.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
                }),
        ];
    }
}
