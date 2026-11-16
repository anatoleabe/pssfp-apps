<?php

declare(strict_types=1);

namespace App\Filament\Resources\BaremeRemunerationResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\BaremeRemunerationResource;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Services\Lmd\DuplicationBaremeEnseignants;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListBaremesRemuneration extends ListRecords
{
    protected static string $resource = BaremeRemunerationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('dupliquer')->label('Dupliquer une année')->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PAIE_PREPARER))
                ->modalHeading('Dupliquer le barème d\'une année')
                ->modalDescription('Toutes les lignes de l\'année copiée sont reprises dans la nouvelle année, à l\'état « proposé » et sans référence de texte : indiquez le texte qui fixe chaque montant de la nouvelle année (Modifier), puis un autre responsable la valide.')
                ->modalSubmitActionLabel('Dupliquer')
                ->fillForm(function (): array {
                    $source = array_key_first(app(DuplicationBaremeEnseignants::class)->anneesSources());

                    return ['source' => $source, 'cible' => $source === null ? null : DuplicationBaremeEnseignants::anneeSuivante($source)];
                })
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('source')->label('Année à copier')->required()->native(false)
                        ->options(fn (): array => app(DuplicationBaremeEnseignants::class)->anneesSources())->live()
                        ->afterStateUpdated(fn (?string $state, Forms\Set $set) => $set('cible', $state === null ? null : DuplicationBaremeEnseignants::anneeSuivante($state))),
                    Forms\Components\TextInput::make('cible')->label('Année à créer')->required()->regex('/^\d{4}-\d{4}$/')->maxLength(9)->placeholder('2027-2028'),
                ]))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    $n = app(DuplicationBaremeEnseignants::class)->dupliquer((string) $data['source'], (string) $data['cible'], auth()->user());

                    return "{$n} lignes créées pour {$data['cible']} : saisissez leur texte de référence, puis faites-les valider.";
                })),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'Seule une ligne validée paie. TD et TP à 0 FCFA : les heures sont tracées sur l\'état sans être payées (D24).';
    }
}
