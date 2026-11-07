<?php

declare(strict_types=1);

namespace App\Filament\Resources\CalendrierAcademiqueResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\CalendrierAcademiqueResource;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListCalendriers extends ListRecords
{
    protected static string $resource = CalendrierAcademiqueResource::class;

    protected function getHeaderActions(): array
    {
        $annee = (int) now('Africa/Douala')->format('n') >= 9 ? (int) now()->format('Y') : (int) now()->format('Y') - 1;

        return [
            Action::make('creer')->label('Préparer un calendrier')->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::CALENDRIER_PREPARER))
                ->form(MessagesValidation::appliquer([
                    Forms\Components\TextInput::make('annee')->label('Année académique')->required()->default(($annee + 1).'-'.($annee + 2))->regex('/^\d{4}-\d{4}$/'),
                    Forms\Components\DatePicker::make('debut')->label('Début de l\'année')->required()->native(false)->displayFormat('d/m/Y')->default(($annee + 1).'-10-01'),
                    Forms\Components\DatePicker::make('fin')->label('Fin de l\'année')->required()->native(false)->displayFormat('d/m/Y')->after('debut')->default(($annee + 2).'-08-31'),
                ], ['regex' => 'Forme attendue : 2027-2028.', 'after' => 'La fin doit suivre le début.']))
                ->action(function (array $data): void {
                    try {
                        $c = app(Calendriers::class)->creer((string) $data['annee'], (string) $data['debut'], (string) $data['fin'], auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->send();

                        return;
                    }
                    $this->redirect(CalendrierAcademiqueResource::getUrl('view', ['record' => $c]));
                }),
        ];
    }
}
