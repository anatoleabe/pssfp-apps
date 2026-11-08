<?php

declare(strict_types=1);

namespace App\Filament\Resources\DossierPaieEnseignantResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\DossierPaieEnseignantResource;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\DossierPaieEnseignant;
use App\Models\User;
use App\Services\Lmd\PointagesEnseignant;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListDossiersPaieEnseignant extends ListRecords
{
    protected static string $resource = DossierPaieEnseignantResource::class;

    public function getTitle(): string
    {
        return 'Dossiers de paie';
    }

    public function getSubheading(): ?string
    {
        return 'Un dossier par enseignant. Il est complet quand le NIU et le compte de virement sont renseignés par le service financier.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ouvrir')->label('Ouvrir des dossiers')->icon('heroicon-o-plus')
                ->visible(fn (): bool => $this->sansDossier() !== [])
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('enseignants')->label('Enseignants sans dossier')->multiple()->required()->native(false)->searchable()
                        ->options(fn (): array => $this->sansDossier())
                        ->helperText('Chaque dossier s\'ouvre au statut « vacataire », à compléter ensuite.'),
                ]))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    $n = 0;
                    foreach (User::query()->whereIn('id', array_map('intval', (array) $data['enseignants']))->get() as $e) {
                        app(PointagesEnseignant::class)->ouvrirDossier($e, auth()->user());
                        $n++;
                    }

                    return $n.' dossier(s) ouvert(s).';
                })),
        ];
    }

    /** @return array<int, string> */
    private function sansDossier(): array
    {
        $avec = DossierPaieEnseignant::query()->pluck('enseignant_id')->all();

        return array_diff_key(ChampsProgrammation::enseignants(), array_flip($avec));
    }
}
