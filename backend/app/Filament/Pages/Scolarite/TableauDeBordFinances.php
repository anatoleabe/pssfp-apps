<?php

declare(strict_types=1);

namespace App\Filament\Pages\Scolarite;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Services\Scolarite\CriteresFinances;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\IndicateursFinanciers;
use App\Support\BelongsToModule;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Url;

/**
 * Tableau de bord de l'espace Finances (spec module-4-scolarite-espace-financier §5, sous-lot F2) : argent (tout l'argent
 * avec la part à confirmer), effectifs (argent confirmé), ventilations, évolution mensuelle, ancienneté des impayés.
 * Lecture seule. Accès : `finances.consulter`.
 */
#[BelongsToModule('scolarite')]
class TableauDeBordFinances extends Page
{
    use ChecksModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = GroupeNavigation::FINANCES;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static ?string $title = 'Tableau de bord financier';

    protected static ?string $slug = 'finances/tableau-de-bord';

    protected static string $view = 'filament.scolarite.tableau-de-bord-finances';

    #[Url]
    public ?string $annee = null;

    #[Url]
    public ?string $campagne = null;

    #[Url]
    public ?string $niveau = null;

    #[Url]
    public ?string $parcours = null;

    #[Url]
    public ?string $statut = null;

    #[Url]
    public bool $retires = false;

    /** @var Collection<int, array<string, mixed>>|null lignes du calcul en cours (une seule fois par rendu) */
    private ?Collection $lignes = null;

    public static function canAccess(): bool
    {
        return static::moduleAllows() && EspaceFinances::peutConsulter(auth()->user());
    }

    public function mount(): void
    {
        $annees = IndicateursFinanciers::annees();
        if ($this->annee === null || ! in_array($this->annee, $annees, true)) {
            $this->annee = $annees[0] ?? null;
        }
    }

    public function updated(): void
    {
        $this->lignes = null;
    }

    public function criteres(): CriteresFinances
    {
        try {
            return CriteresFinances::depuis([
                'annee' => $this->annee, 'campagne_id' => $this->campagne, 'niveau' => $this->niveau, 'parcours' => $this->parcours,
                'statuts' => $this->statut === null || $this->statut === '' ? [] : [$this->statut], 'inclure_retires' => $this->retires,
            ]);
        } catch (InvalidArgumentException) {
            return CriteresFinances::depuis(['annee' => $this->annee]);
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    public function lignes(): Collection
    {
        return $this->lignes ??= app(IndicateursFinanciers::class)->lignes($this->criteres());
    }

    /** @return array<string, array<string, string>> listes de choix des filtres */
    public function options(): array
    {
        return [
            'annees' => array_combine($a = IndicateursFinanciers::annees(), $a) ?: [],
            'campagnes' => CampagneCandidature::query()->whereNotNull('promotion_numero')->orderByDesc('promotion_numero')
                ->pluck('promotion_numero', 'id')->map(fn ($n): string => $n.'e promotion')->all(),
            'niveaux' => BaremeScolarite::NIVEAUX,
            'parcours' => collect(app(IndicateursFinanciers::class)->parcoursConnus($this->criteres()))->mapWithKeys(fn (string $p): array => [$p => $p])->all(),
            'statuts' => Auditeur::STATUTS,
        ];
    }

    /** Ventilations affichées : l'année seulement quand elle n'est pas filtrée. @return array<string, string> */
    public function dimensions(): array
    {
        $dimensions = array_intersect_key(IndicateursFinanciers::DIMENSIONS, array_flip(['promotion', 'niveau', 'parcours', 'statut', 'financement']));

        return $this->annee === null ? ['annee' => IndicateursFinanciers::DIMENSIONS['annee']] + $dimensions : $dimensions;
    }
}
