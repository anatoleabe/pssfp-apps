<?php

declare(strict_types=1);

namespace App\Filament\Pages\Lmd;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\EvenementCalendrier;
use App\Services\Lmd\ControlesPointage;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PointagesEnseignant;
use App\Support\BelongsToModule;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** Suivi des heures enseignées d'une période (lot 13a ; D23) : contrôles du pointage, bilan par enseignant et par cours. */
#[BelongsToModule('lmd')]
class SuiviHeuresEnseignees extends Page implements HasForms
{
    use ChecksModuleAccess;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 48;

    protected static ?string $title = 'Suivi des heures enseignées';

    protected static ?string $slug = 'lmd/suivi-heures';

    protected static string $view = 'filament.lmd.suivi-heures';

    #[Url]
    public ?string $periode = null;

    /** @var array<string, mixed> */
    public array $donnees = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return static::moduleAllows() && $u !== null && ($u->can(PermissionsLmd::CONSULTER) || $u->can(PermissionsLmd::POINTAGE));
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

    /** @var array<int, array<string, mixed>> Suivi calculé une fois par requête et par période. */
    private array $calcul = [];

    /** Période choisie, revérifiée à chaque requête (le paramètre d'URL est modifiable côté navigateur). */
    public function choisie(): ?EvenementCalendrier
    {
        if ($this->periode === null || ! array_key_exists((int) $this->periode, ChampsProgrammation::periodes())) {
            return null;
        }

        return EvenementCalendrier::query()->with('calendrier')->find((int) $this->periode);
    }

    /** @return array{controles: list<array{controle: string, detail: string}>, bilan: list<array<string, mixed>>} */
    public function suivi(EvenementCalendrier $p): array
    {
        if (! isset($this->calcul[$p->id])) {
            $bilan = app(PointagesEnseignant::class)->bilan($p);
            $this->calcul[$p->id] = ['controles' => app(ControlesPointage::class)->pour($p, $bilan), 'bilan' => $bilan];
        }

        return $this->calcul[$p->id];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('parametres')->label('Paramètres du pointage')->icon('heroicon-o-adjustments-horizontal')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PROGRAMMATION_VALIDER))
                ->fillForm(fn (): array => ['arrondi' => (string) app(PointagesEnseignant::class)->arrondi(), 'delai' => app(PointagesEnseignant::class)->delai()])
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('arrondi')->label('Arrondi des heures payables (au pas inférieur)')->native(false)->required()
                        ->options(['1' => '1 minute', '5' => '5 minutes', '10' => '10 minutes', '15' => '15 minutes (proposé)', '30' => '30 minutes']),
                    Forms\Components\TextInput::make('delai')->label('Délai de validation d\'un pointage')->numeric()->integer()->minValue(1)->maxValue(60)->required()->suffix('jours')
                        ->helperText('Au-delà, le pointage est signalé « en attente » au suivi.'),
                ]))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    app(PointagesEnseignant::class)->definirParametres((int) $data['arrondi'], (int) $data['delai'], auth()->user());

                    return 'Paramètres enregistrés. Ils s\'appliquent aux prochaines validations.';
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
