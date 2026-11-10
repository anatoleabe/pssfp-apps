<?php

declare(strict_types=1);

namespace App\Filament\Pages\Lmd;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Services\Lmd\ControleGestion;
use App\Support\BelongsToModule;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Lot 13c-2 (D38) : contrôle de gestion. Coûts par UE et par parcours, tableau de bord mensuel, dossiers en retard.
 * Direction et service financier (`lmd.controle_gestion`). La pastille compte les dossiers en retard.
 */
#[BelongsToModule('lmd')]
class ControleGestionPage extends Page
{
    use ChecksModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = GroupeNavigation::ENSEIGNANTS_PAIE;

    protected static ?int $navigationSort = 80;

    protected static ?string $navigationLabel = 'Contrôle de gestion';

    protected static ?string $title = 'Contrôle de gestion';

    protected static ?string $slug = 'lmd/controle-gestion';

    protected static string $view = 'filament.lmd.controle-gestion';

    #[Url]
    public ?string $annee = null;

    public static function canAccess(): bool
    {
        return static::moduleAllows() && ControleGestion::peutConsulter(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        if (! ControleGestion::peutConsulter(auth()->user())) {
            return null;
        }
        $n = app(ControleGestion::class)->nombreEnRetard();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Dossiers en retard';
    }

    public function mount(): void
    {
        $annees = $this->annees();
        if ($this->annee === null || ! in_array($this->annee, $annees, true)) {
            $this->annee = $annees[0] ?? null;
        }
    }

    /** @return list<string> */
    public function annees(): array
    {
        return app(ControleGestion::class)->annees();
    }

    /** @return array{ues: list<array<string, mixed>>, parcours: list<array<string, mixed>>, hors_ue: int} */
    public function couts(): array
    {
        if ($this->annee === null || ! in_array($this->annee, $this->annees(), true)) {
            return ['ues' => [], 'parcours' => [], 'hors_ue' => 0];
        }
        $svc = app(ControleGestion::class);
        $ues = $svc->coutsUe($this->annee);

        return ['ues' => $ues, 'parcours' => $svc->coutsParcours($this->annee, $ues), 'hors_ue' => $svc->horsUe($this->annee)];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delaiRequetes')->label('Délai des requêtes')->icon('heroicon-o-adjustments-horizontal')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('settings.manage'))
                ->modalDescription('Au-delà de ce délai depuis son dépôt, une requête ouverte figure parmi les dossiers en retard (en attendant le règlement des examens).')
                ->fillForm(fn (): array => ['jours' => app(ControleGestion::class)->delaiRequetes()])
                ->form(MessagesValidation::appliquer([
                    Forms\Components\TextInput::make('jours')->label('Délai de traitement (jours)')->integer()->minValue(1)->maxValue(365)->required(),
                ]))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    app(ControleGestion::class)->definirDelaiRequetes((int) $data['jours'], auth()->user());

                    return 'Délai des requêtes réglé.';
                })),
        ];
    }
}
