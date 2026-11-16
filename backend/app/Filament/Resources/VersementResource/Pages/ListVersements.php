<?php

declare(strict_types=1);

namespace App\Filament\Resources\VersementResource\Pages;

use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\VersementResource;
use App\Models\Auditeur;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\VersementsExcel;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListVersements extends ListRecords
{
    protected static string $resource = VersementResource::class;

    /** Auditeurs proposés au plus dans la recherche. */
    private const AUDITEURS_MAX = 20;

    public function getSubheading(): ?string
    {
        return 'Tous les versements des auditeurs. Un versement ne se modifie ni ne se supprime : une erreur se corrige par une annulation motivée, qui reste visible ici.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->encaisser(),
            Action::make('exporter')
                ->label('Exporter (Excel)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => EspaceFinances::peutExporter(auth()->user()))
                ->action(function (): ?StreamedResponse {
                    abort_unless(EspaceFinances::peutExporter(auth()->user()), 403);
                    $requete = $this->getFilteredSortedTableQuery();
                    $versements = VersementsExcel::lireVersements($requete);
                    if ($versements === null) {
                        Notification::make()->warning()->title('Export trop volumineux')
                            ->body('Plus de '.number_format(VersementsExcel::plafond(), 0, ',', ' ').' versements : affinez les filtres (période, année).')->send();

                        return null;
                    }
                    EspaceFinances::journaliserExport(auth()->user(), 'versements', $this->criteres(), (clone $requete)->count());
                    $contenu = app(VersementsExcel::class)->generer($versements);

                    return response()->streamDownload(function () use ($contenu): void {
                        echo $contenu;
                    }, 'versements-'.now('Africa/Douala')->format('Y-m-d').'.xlsx', [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]);
                }),
        ];
    }

    /** « Enregistrer un versement » sans passer par la fiche : choix de l'auditeur, puis la saisie de la fiche (spec §6.2). */
    private function encaisser(): Action
    {
        return Action::make('encaisser')
            ->label(fn (): string => AuditeurActions::encaisseAvecRecu() ? 'Enregistrer et émettre le reçu' : 'Enregistrer un versement')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (): bool => (bool) auth()->user()?->can(RegistreVersements::PERMISSION_SAISIR))
            ->modalHeading(fn (): string => AuditeurActions::encaisseAvecRecu() ? 'Enregistrer le versement et émettre son reçu' : 'Enregistrer un versement')
            ->modalDescription('Choisissez l\'auditeur par son matricule ou son nom. Le versement compte aussitôt sur son inscription courante ; seul le responsable de scolarité peut l\'annuler.')
            ->modalSubmitActionLabel(fn (): string => AuditeurActions::encaisseAvecRecu() ? 'Enregistrer et émettre le reçu' : 'Enregistrer')
            ->form(fn (): array => MessagesValidation::appliquer([
                Forms\Components\Select::make('auditeur_id')->label('Auditeur')->required()->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search): array => self::auditeurs($search))
                    ->getOptionLabelUsing(fn ($value): ?string => ($a = self::auditeur($value)) === null ? null : self::libelle($a)),
                ...AuditeurActions::champsVersement(fn (Forms\Get $get): ?Auditeur => self::auditeur($get('auditeur_id'))),
            ]))
            ->action(function (array $data, Action $action): void {
                $auditeur = self::auditeur($data['auditeur_id'] ?? null);
                if ($auditeur === null) {
                    AuditeurActions::supprimerScanOrphelin($data['justificatif_path'] ?? null);
                    Notification::make()->danger()->title('Versement non enregistré')
                        ->body('Cet auditeur n\'a pas d\'inscription en cours, ou son dossier est retiré.')->send();
                    $action->halt();

                    return;
                }
                AuditeurActions::encaisserPour($auditeur, $data, $action);
            });
    }

    /** @return array<int, string> identifiant => « MATRICULE — NOM Prénom » */
    private static function auditeurs(string $search): array
    {
        $motif = '%'.addcslashes(trim($search), '%_\\').'%';

        return self::eligibles()
            ->whereHas('candidature', fn (Builder $c) => $c->where(fn (Builder $w) => $w->where('matricule', 'ilike', $motif)
                ->orWhere('nom', 'ilike', $motif)->orWhere('prenom', 'ilike', $motif)))
            ->with('candidature:id,nom,prenom,matricule')
            ->limit(self::AUDITEURS_MAX)->get()
            ->mapWithKeys(fn (Auditeur $a): array => [$a->id => self::libelle($a)])->all();
    }

    /** Auditeur visé par la saisie : inscription en cours, dossier non retiré. */
    private static function auditeur(mixed $id): ?Auditeur
    {
        return is_numeric($id) ? self::eligibles()->with(['candidature', 'inscriptionCourante'])->find((int) $id) : null;
    }

    /** @return Builder<Auditeur> */
    private static function eligibles(): Builder
    {
        return Auditeur::query()->whereHas('inscriptionCourante')
            ->whereHas('candidature', fn (Builder $c) => $c->whereNull('withdrawn_at'));
    }

    private static function libelle(Auditeur $a): string
    {
        $c = $a->candidature;

        return trim(($c?->matricule ?? '—').' — '.mb_strtoupper((string) $c?->nom).' '.$c?->prenom);
    }

    /**
     * Critères de l'export pour le journal : filtres actifs et présence d'une recherche, jamais sa valeur (elle peut
     * être un nom).
     *
     * @return array<string, mixed>
     */
    private function criteres(): array
    {
        return [
            'filtres' => collect($this->tableFilters ?? [])->filter(fn ($v): bool => filled(array_filter((array) $v, fn ($x): bool => filled($x))))->all(),
            'recherche' => filled($this->tableSearch),
        ];
    }
}
