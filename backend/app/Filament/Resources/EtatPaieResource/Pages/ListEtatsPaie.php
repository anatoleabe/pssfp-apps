<?php

declare(strict_types=1);

namespace App\Filament\Resources\EtatPaieResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\EtatPaieResource;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListEtatsPaie extends ListRecords
{
    protected static string $resource = EtatPaieResource::class;

    public function getSubheading(): ?string
    {
        return 'Un état ordinaire par mois, préparé à partir des pointages validés. Circuit : vérification par le responsable de la scolarité, transmission au service financier, prise en charge, virement.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preparer')->label('Préparer l\'état d\'un mois')->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PAIE_PREPARER))
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('mois')->label('Mois')->required()->native(false)
                        ->options(fn (): array => $this->mois())
                        ->default(fn (): string => now('Africa/Douala')->subMonthNoOverflow()->format('Y-m'))
                        ->helperText('Les pointages validés dont la séance tombe dans le mois, et pas encore payés.'),
                ]))
                ->action(function (array $data): void {
                    $etat = null;
                    ChampsProgrammation::executer(function () use ($data, &$etat): string {
                        $etat = app(EtatsPaie::class)->creerMensuel((string) $data['mois'], auth()->user());

                        return 'État '.$etat->numero.' préparé.';
                    });
                    $this->redirect(EtatPaieResource::getUrl('view', ['record' => $etat]));
                }),
        ];
    }

    /** Les douze derniers mois, mois en cours compris. @return array<string, string> */
    private function mois(): array
    {
        $o = [];
        $m = now('Africa/Douala')->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $o[$m->format('Y-m')] = ucfirst($m->locale('fr')->translatedFormat('F Y'));
            $m = $m->subMonthNoOverflow();
        }

        return $o;
    }
}
