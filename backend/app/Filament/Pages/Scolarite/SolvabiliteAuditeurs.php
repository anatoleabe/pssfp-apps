<?php

declare(strict_types=1);

namespace App\Filament\Pages\Scolarite;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\User;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\ListeSolvabilite;
use App\Services\Scolarite\Solvabilite;
use App\Support\BelongsToModule;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finances > Solvabilité (17/11/2026) : auditeurs en règle, partiellement et totalement insolvables, sur tout le
 * parcours, versements à confirmer comptés (`Solvabilite`). Un onglet par situation, export PDF et Excel de l'onglet.
 * Lecture : `finances.consulter` ; export : `scolarite.export`.
 */
#[BelongsToModule('scolarite')]
class SolvabiliteAuditeurs extends Page
{
    use ChecksModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = GroupeNavigation::FINANCES;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Solvabilité';

    protected static ?string $title = 'Solvabilité des auditeurs';

    protected static ?string $slug = 'finances/solvabilite';

    protected static string $view = 'filament.scolarite.solvabilite-auditeurs';

    #[Url]
    public string $onglet = Solvabilite::TOTALE;

    #[Url]
    public ?string $campagne = null;

    #[Url]
    public ?string $niveau = null;

    #[Url]
    public ?string $statut = Auditeur::STATUT_ACTIF;

    #[Url]
    public bool $retires = false;

    /** @var Collection<int, array<string, mixed>>|null calcul du rendu en cours */
    private ?Collection $lignes = null;

    public static function canAccess(): bool
    {
        return static::moduleAllows() && EspaceFinances::peutConsulter(auth()->user());
    }

    public function mount(): void
    {
        if (! array_key_exists($this->onglet, Solvabilite::LIBELLES)) {
            $this->onglet = Solvabilite::TOTALE;
        }
    }

    public function updated(): void
    {
        $this->lignes = null;
    }

    /** @return array<string, mixed> */
    public function criteres(): array
    {
        return ['campagne_id' => $this->campagne, 'niveau' => $this->niveau, 'statut' => $this->statut, 'inclure_retires' => $this->retires];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function lignes(): Collection
    {
        try {
            return $this->lignes ??= app(ListeSolvabilite::class)->lignes($this->criteres());
        } catch (InvalidArgumentException) {
            return $this->lignes = collect();
        }
    }

    /** @return array<string, array<string, string>> */
    public function options(): array
    {
        return [
            'campagnes' => ListeSolvabilite::promotions(),
            'niveaux' => BaremeScolarite::NIVEAUX,
            'statuts' => Auditeur::STATUTS,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [$this->export('exporterPdf', 'pdf', 'PDF', 'heroicon-o-document-arrow-down'), $this->export('exporterExcel', 'xlsx', 'Excel', 'heroicon-o-table-cells')];
    }

    private function export(string $nom, string $format, string $libelle, string $icone): Action
    {
        return Action::make($nom)
            ->label(fn (): string => 'Exporter l\'onglet ('.$libelle.')')
            ->icon($icone)->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can(ListeSolvabilite::PERMISSION_EXPORT))
            ->action(function () use ($format): ?StreamedResponse {
                $agent = auth()->user();
                abort_unless($agent instanceof User, 403);
                $listes = app(ListeSolvabilite::class);
                try {
                    $contenu = $format === 'pdf'
                        ? $listes->pdf($this->criteres(), $this->onglet, $agent)
                        : $listes->excel($this->criteres(), $this->onglet, $agent);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Export impossible')->body($e->getMessage())->send();

                    return null;
                }
                $type = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

                return response()->streamDownload(function () use ($contenu): void {
                    echo $contenu;
                }, ListeSolvabilite::nomFichier($this->onglet, $format), ['Content-Type' => $type]);
            });
    }
}
