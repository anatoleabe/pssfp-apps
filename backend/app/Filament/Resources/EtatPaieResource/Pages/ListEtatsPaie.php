<?php

declare(strict_types=1);

namespace App\Filament\Resources\EtatPaieResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\EtatPaieResource;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\EtatPaie;
use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListEtatsPaie extends ListRecords
{
    protected static string $resource = EtatPaieResource::class;

    public function getSubheading(): ?string
    {
        return 'Un état ordinaire par mois, préparé à partir des pointages et des primes d\'animation validés. Circuit : vérification par le responsable de la scolarité, transmission au service financier, prise en charge, virement.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('visa')->label('Visa du contrôleur interne')->icon('heroicon-o-shield-check')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('settings.manage'))
                ->fillForm(fn (): array => ['actif' => app(EtatsPaie::class)->visaRequis()])
                ->form([
                    Forms\Components\Toggle::make('actif')->label('Exiger le visa du contrôleur interne avant la transmission')
                        ->helperText('Attribuez alors la permission « Viser les états de paie » au contrôleur interne, depuis l\'écran des rôles.'),
                ])
                ->modalSubmitActionLabel('Enregistrer')
                ->action(function (array $data): void {
                    app(EtatsPaie::class)->definirVisa((bool) $data['actif'], auth()->user());
                    Notification::make()->success()->title($data['actif'] ? 'Visa exigé.' : 'Visa non exigé.')->send();
                }),
            Action::make('preparer')->label('Préparer l\'état d\'un mois')->icon('heroicon-o-plus')->modalSubmitActionLabel('Préparer')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PAIE_PREPARER))
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('mois')->label('Mois')->required()->native(false)
                        ->options(fn (): array => $this->mois())
                        ->disableOptionWhen(fn (string $value): bool => EtatPaie::query()->where('periode_du', $value.'-01')->whereNull('rectificatif_de')
                            ->where('statut', '<>', EtatPaie::REJETE)->exists())
                        ->default(fn (): string => now('Africa/Douala')->subMonthNoOverflow()->format('Y-m'))
                        ->helperText('Les pointages validés dont la séance tombe dans le mois, et les primes d\'animation validées des cours qui s\'y terminent, pas encore payés.'),
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
            $o[$m->format('Y-m')] = ucfirst($m->locale('fr')->translatedFormat('F Y')).($i === 0 ? ' (en cours)' : '');
            $m = $m->subMonthNoOverflow();
        }

        return $o;
    }
}
