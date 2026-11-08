<?php

declare(strict_types=1);

namespace App\Filament\Pages\Lmd;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\EvenementCalendrier;
use App\Services\Lmd\ControlesProgrammation;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\Programmations;
use App\Support\BelongsToModule;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** Suivi de la programmation d'une période (lot 12d ; D22) : contrôles, charge des enseignants, avancement des quotas. */
#[BelongsToModule('lmd')]
class SuiviProgrammation extends Page implements HasForms
{
    use ChecksModuleAccess;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-bar';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 45;

    protected static ?string $title = 'Suivi de la programmation';

    protected static ?string $slug = 'lmd/suivi-programmation';

    protected static string $view = 'filament.lmd.suivi-programmation';

    #[Url]
    public ?string $periode = null;

    /** @var array<string, mixed> */
    public array $donnees = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return static::moduleAllows() && $u !== null
            && ($u->can(PermissionsLmd::CONSULTER) || $u->can(PermissionsLmd::PROGRAMMATION) || $u->can(PermissionsLmd::PROGRAMMATION_VALIDER));
    }

    public function mount(): void
    {
        $periodes = ChampsProgrammation::periodes();
        $courante = $this->periode !== null && array_key_exists((int) $this->periode, $periodes) ? (int) $this->periode : $this->periodeCourante(array_keys($periodes));
        $this->form->fill(['periode' => $courante]);
        $this->periode = $courante === null ? null : (string) $courante;
    }

    public function form(Form $form): Form
    {
        return $form->statePath('donnees')->schema([
            Forms\Components\Select::make('periode')->label('Période de cours')->native(false)->searchable()->live()
                ->options(fn (): array => ChampsProgrammation::periodes())
                ->afterStateUpdated(fn (?string $state) => $this->periode = $state),
        ]);
    }

    public function choisie(): ?EvenementCalendrier
    {
        return $this->periode === null ? null : EvenementCalendrier::query()->with('calendrier')->find((int) $this->periode);
    }

    /** @return array{controles: list<array{controle: string, detail: string}>, charges: list<array<string, mixed>>, quotas: list<array<string, mixed>>} */
    public function suivi(EvenementCalendrier $p): array
    {
        return [
            'controles' => app(ControlesProgrammation::class)->pour($p),
            'charges' => app(Programmations::class)->charges($p),
            'quotas' => app(Programmations::class)->quotasPeriode($p),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('plafond')->label('Plafond de charge')->icon('heroicon-o-scale')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PROGRAMMATION_VALIDER))
                ->fillForm(fn (): array => ['heures' => app(Programmations::class)->plafond()])
                ->form(MessagesValidation::appliquer([
                    Forms\Components\TextInput::make('heures')->label('Heures par enseignant et par période')->numeric()->minValue(1)->maxValue(999)->suffix('h')
                        ->helperText('Laissez vide : pas de plafond.'),
                ]))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    $h = blank($data['heures'] ?? null) ? null : (float) $data['heures'];
                    app(Programmations::class)->definirPlafond($h, auth()->user());

                    return $h === null ? 'Plafond retiré.' : 'Plafond fixé à '.$h.' h.';
                })),
        ];
    }

    /** Période en cours, sinon la prochaine, sinon la dernière. @param list<int> $ids */
    private function periodeCourante(array $ids): ?int
    {
        $aujourdhui = now('Africa/Douala')->toDateString();
        $periodes = EvenementCalendrier::query()->whereIn('id', $ids)->orderBy('debut_prevu')->get();

        return ($periodes->first(fn (EvenementCalendrier $e): bool => $e->fin_prevue->toDateString() >= $aujourdhui) ?? $periodes->last())?->id;
    }
}
