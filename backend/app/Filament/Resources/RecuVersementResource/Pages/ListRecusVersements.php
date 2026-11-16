<?php

declare(strict_types=1);

namespace App\Filament\Resources\RecuVersementResource\Pages;

use App\Filament\Resources\AuditeurResource\RecuActions;
use App\Filament\Resources\RecuVersementResource;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\VersementsExcel;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListRecusVersements extends ListRecords
{
    protected static string $resource = RecuVersementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exporterRegistre')
                ->label('Registre des reçus (Excel)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => EspaceFinances::peutExporter(auth()->user()))
                ->action(function (): StreamedResponse {
                    abort_unless(EspaceFinances::peutExporter(auth()->user()), 403);
                    // Mêmes filtres que la liste, triés par numéro : le registre se lit dans l'ordre d'émission.
                    $recus = $this->getFilteredTableQuery()->reorder()->orderBy('annee_numero')->orderBy('sequence')->get();
                    EspaceFinances::journaliserExport(auth()->user(), 'recus', [
                        'filtres' => collect($this->tableFilters ?? [])->filter(fn ($v): bool => filled(array_filter((array) $v, fn ($x): bool => filled($x))))->all(),
                        'recherche' => filled($this->tableSearch),
                    ], $recus->count());
                    $contenu = app(VersementsExcel::class)->registre($recus);

                    return response()->streamDownload(function () use ($contenu): void {
                        echo $contenu;
                    }, 'registre-recus-'.now('Africa/Douala')->format('Y-m-d').'.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return trim($this->attente().' Un reçu se délivre depuis l\'onglet Versements de la fiche de l\'auditeur. Il ne se modifie jamais : une erreur se corrige par une écriture d\'annulation.');
    }

    /**
     * Pour le service financier : d'où part la liste d'attente et ce qu'elle compte (bascule ACMA, spec
     * lot 4 §5). Après la bascule, un versement plus ancien a déjà son reçu ACMA.
     */
    private function attente(): string
    {
        $recus = app(RecusVersements::class);
        $jour = $recus->jourDebutAttente();
        if (! RecuActions::peutEmettre() || $jour === null) {
            return '';
        }
        $n = $recus->enAttente(auth()->user())->count();
        $ligne = 'Versements enregistrés depuis le '.$jour.' : '.($n === 0 ? 'aucun' : (string) $n).' en attente de reçu.';

        return $recus->bascule() === null ? $ligne : $ligne.' Un versement plus ancien reçoit son reçu à la demande de l\'auditeur, depuis sa fiche.';
    }
}
