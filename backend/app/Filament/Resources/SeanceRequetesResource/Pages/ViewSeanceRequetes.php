<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeanceRequetesResource\Pages;

use App\Filament\Resources\SeanceRequetesResource;
use App\Models\SeanceRequetes;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\Requetes;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewSeanceRequetes extends ViewRecord
{
    protected static string $resource = SeanceRequetesResource::class;

    protected function getHeaderActions(): array
    {
        $s = fn (): SeanceRequetes => $this->getRecord()->refresh();
        $u = fn (): User => auth()->user();

        return [
            Action::make('clore')->label('Clore le PV')->icon('heroicon-o-lock-closed')->color('warning')
                ->visible(fn (): bool => ! $s()->estClose() && $u()->can(PermissionsLmd::REQUETES_JURY))
                ->requiresConfirmation()->modalDescription('Clos, le PV ne se modifie plus. Les notes ne changent qu\'à sa validation définitive.')
                ->action(fn () => $this->executer(fn (): string => 'PV clos : '.app(Requetes::class)->cloreSeance($s(), $u()))),
            Action::make('valider')->label('Validation définitive')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (): bool => $s()->estClose() && $s()->validee_at === null && $u()->can(PermissionsLmd::REQUETES_VALIDER))
                ->requiresConfirmation()->modalDescription('Les mentions s\'appliquent : les notes des requêtes fondées sont corrigées, avec le numéro de la requête pour motif.')
                ->action(fn () => $this->executer(fn (): string => app(Requetes::class)->validerSeance($s(), $u()).' note(s) corrigée(s).')),
            Action::make('pv')->label('PV (PDF)')->icon('heroicon-o-document-arrow-down')->color('gray')
                ->visible(fn (): bool => $s()->estClose())
                ->action(function () use ($s): StreamedResponse {
                    $pdf = Pdf::loadView('pdf.lmd.pv-requetes', ['s' => $s()->load(['jury.membres', 'requetes.auditeur.candidature', 'requetes.ecue'])])->setPaper('a4', 'landscape')->output();

                    return response()->streamDownload(fn () => print ($pdf), mb_strtolower((string) $s()->numero_pv).'.pdf', ['Content-Type' => 'application/pdf']);
                }),
        ];
    }

    private function executer(callable $operation): void
    {
        try {
            $message = $operation();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title($message)->send();
    }
}
