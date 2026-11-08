<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyntheseAnimationResource\Pages;

use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Filament\Resources\SyntheseAnimationResource;
use App\Services\Lmd\SynthesesAnimation;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListSynthesesAnimation extends ListRecords
{
    protected static string $resource = SyntheseAnimationResource::class;

    public function getSubheading(): ?string
    {
        $s = app(SynthesesAnimation::class);
        $mode = $s->mode() === SynthesesAnimation::SEUIL ? 'prime entière si le taux atteint '.str_replace('.', ',', (string) $s->seuil()).' %' : 'au prorata des activités réalisées';

        return 'Une prime par enseignant et par cours en ligne, au montant du barème de l\'année, '.$mode.' (D25) ; payée sur l\'état du mois où le cours se termine.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mode')->label('Mode de calcul')->icon('heroicon-o-adjustments-horizontal')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('settings.manage'))
                ->fillForm(fn (): array => ['mode' => app(SynthesesAnimation::class)->mode(), 'seuil' => app(SynthesesAnimation::class)->seuil()])
                ->form([
                    Forms\Components\Radio::make('mode')->label('Calcul de la prime')->options(SynthesesAnimation::MODES)->required(),
                    Forms\Components\TextInput::make('seuil')->label('Seuil (mode seuil seulement)')->numeric()->suffix('%')->required()->minValue(1)->maxValue(100),
                ])
                ->modalSubmitActionLabel('Enregistrer')
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    app(SynthesesAnimation::class)->definirMode((string) $data['mode'], (float) $data['seuil'], auth()->user());

                    return 'Mode de calcul enregistré : il s\'applique aux prochaines générations d\'états.';
                })),
            CreateAction::make()->label('Nouvelle synthèse'),
        ];
    }
}
