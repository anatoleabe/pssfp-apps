<?php

declare(strict_types=1);

namespace App\Filament\Resources\DossierMemoireResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\DossierMemoireResource;
use App\Models\Deliberation;
use App\Services\Lmd\Memoires;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListDossiersMemoire extends ListRecords
{
    protected static string $resource = DossierMemoireResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ouvrir')->label('Ouvrir les dossiers des AS')->icon('heroicon-o-folder-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::MEMOIRES_SUIVRE))
                ->modalDescription('Un dossier de mémoire est ouvert pour chaque auditeur autorisé à soutenir (AS) de la délibération du M2 arrêtée.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('deliberation_id')->label('Délibération')->required()->native(false)
                        ->options(fn (): array => Deliberation::query()->where('niveau', 'M2')->where('statut', Deliberation::ARRETEE)->orderByDesc('id')->get()
                            ->mapWithKeys(fn (Deliberation $d): array => [$d->id => $d->libelle()])->all()),
                ]))
                ->action(function (array $data): void {
                    try {
                        $n = app(Memoires::class)->ouvrirPourDeliberation(Deliberation::query()->findOrFail($data['deliberation_id']), auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title($n.' dossier(s) ouvert(s).')->send();
                }),
        ];
    }
}
