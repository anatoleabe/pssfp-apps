<?php

declare(strict_types=1);

namespace App\Filament\Pages\Scolarite;

use App\Models\Auditeur;
use App\Models\CampagneCandidature;
use App\Services\Scolarite\AnalyseFinancesExcel;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\IndicateursFinanciers;
use App\Services\Scolarite\LibelleSituation;
use App\Support\BelongsToModule;
use Filament\Actions\Action;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analyse de l'espace Finances (spec module-4-scolarite-espace-financier §6.1, sous-lot F3) : tableau croisé sur un ou deux
 * regroupements, ligne de total recalculée sur tout le périmètre, export Excel en trois feuilles. Même périmètre et même
 * calcul que le tableau de bord (IndicateursFinanciers), plus la situation et le financement. Lecture seule.
 */
#[BelongsToModule('scolarite')]
class AnalyseFinances extends TableauDeBordFinances
{
    protected static ?int $navigationSort = 7;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Analyse';

    protected static ?string $title = 'Analyse financière';

    protected static ?string $slug = 'finances/analyse';

    protected static string $view = 'filament.scolarite.analyse-finances';

    #[Url]
    public string $lignesPar = 'promotion';

    #[Url]
    public ?string $puisPar = null;

    #[Url]
    public ?string $situation = null;

    #[Url]
    public ?string $financement = null;

    /** @return list<string> regroupements retenus, connus et distincts */
    public function regroupements(): array
    {
        $choisis = array_values(array_unique(array_filter([$this->lignesPar, $this->puisPar])));

        return array_values(array_intersect($choisis, array_keys(IndicateursFinanciers::DIMENSIONS)));
    }

    /** Lignes du tableau de bord, restreintes à la situation et au financement choisis. @return Collection<int, array<string, mixed>> */
    public function lignesAnalyse(): Collection
    {
        return $this->lignes()
            ->when(filled($this->situation), fn (Collection $c) => $c->where('situation', $this->situation))
            ->when(filled($this->financement), fn (Collection $c) => $c->where('financement', $this->financement))
            ->values();
    }

    /** @return array<string, string> critères lisibles, en tête de l'export */
    public function libellesCriteres(): array
    {
        $c = $this->criteres();

        return [
            'Année académique' => $c->annee ?? 'toutes',
            'Promotion' => $c->campagneId === null ? 'toutes' : (CampagneCandidature::query()->whereKey($c->campagneId)->value('promotion_numero').'e promotion'),
            'Niveau' => $c->niveau ?? 'tous',
            'Parcours' => $c->parcours ?? 'tous',
            'Statut' => count($c->statuts) === count(Auditeur::STATUTS) ? 'tous' : implode(', ', array_map(fn (string $s): string => Auditeur::STATUTS[$s], $c->statuts)),
            'Dossiers retirés' => $c->inclureRetires ? 'inclus' : 'exclus',
            'Situation' => filled($this->situation) ? (LibelleSituation::LIBELLES[$this->situation] ?? $this->situation) : 'toutes',
            'Financement' => filled($this->financement) ? (IndicateursFinanciers::FINANCEMENTS[$this->financement] ?? $this->financement) : 'tous',
            'Regroupement' => implode(' puis ', array_map(fn (string $d): string => IndicateursFinanciers::DIMENSIONS[$d], $this->regroupements())) ?: 'aucun',
            'Édité le' => now('Africa/Douala')->format('d/m/Y à H:i'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exporter')->label('Exporter (Excel)')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (): bool => EspaceFinances::peutExporter(auth()->user()))
                ->action(function (): StreamedResponse {
                    abort_unless(EspaceFinances::peutExporter(auth()->user()), 403);
                    $lignes = $this->lignesAnalyse();
                    EspaceFinances::journaliserExport(auth()->user(), 'analyse', $this->criteres()->proprietes()
                        + ['situation' => $this->situation, 'financement' => $this->financement, 'regroupements' => $this->regroupements()], $lignes->count());
                    $contenu = app(AnalyseFinancesExcel::class)->generer($lignes, $this->regroupements(), $this->libellesCriteres());

                    return response()->streamDownload(function () use ($contenu): void {
                        echo $contenu;
                    }, 'analyse-financiere-'.now('Africa/Douala')->format('Y-m-d').'.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
                }),
        ];
    }
}
