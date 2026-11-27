<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanningExamensResource\Pages;

use App\Filament\Resources\PlanningExamensResource;
use App\Models\PlanningExamens;
use App\Services\Lmd\DocumentsExamen;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PlanningsExamens;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewPlanningExamens extends ViewRecord
{
    protected static string $resource = PlanningExamensResource::class;

    public function getTitle(): string
    {
        return 'Planning — '.$this->getRecord()->libelle();
    }

    protected function getHeaderActions(): array
    {
        $p = fn (): PlanningExamens => $this->getRecord()->refresh();
        $nom = fn (string $prefixe): string => $prefixe.'-'.Str::slug($p()->libelle()).'.pdf';

        return [
            Action::make('publier')->label('Publier')->icon('heroicon-o-megaphone')->color('success')
                ->visible(fn (): bool => ! $p()->estPublie() && (bool) auth()->user()?->can(PermissionsLmd::EMPLOIS_DU_TEMPS))
                ->requiresConfirmation()->modalDescription('Les auditeurs voient leurs épreuves et leur salle dans leur espace. La publication est refusée tant qu\'il reste un conflit bloquant.')
                ->action(function () use ($p): void {
                    try {
                        app(PlanningsExamens::class)->publier($p(), auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Publication refusée')->body($e->getMessage())->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('Planning publié.')->send();
                    $this->redirect(PlanningExamensResource::getUrl('view', ['record' => $p()]));
                }),
            Action::make('planningPdf')->label('Planning (PDF)')->icon('heroicon-o-calendar-days')->color('gray')
                ->action(function () use ($p, $nom): StreamedResponse {
                    $pdf = app(DocumentsExamen::class)->planning($p());

                    return response()->streamDownload(fn () => print ($pdf), $nom('planning'), ['Content-Type' => 'application/pdf']);
                }),
            Action::make('emargements')->label('Listes d\'émargement (PDF)')->icon('heroicon-o-list-bullet')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::EMPLOIS_DU_TEMPS))
                ->action(function () use ($p, $nom): StreamedResponse {
                    $pdf = app(DocumentsExamen::class)->emargements($p(), $p()->epreuves()->with(['salles', 'surveillants'])->get());

                    return response()->streamDownload(fn () => print ($pdf), $nom('emargements'), ['Content-Type' => 'application/pdf']);
                }),
        ];
    }
}
