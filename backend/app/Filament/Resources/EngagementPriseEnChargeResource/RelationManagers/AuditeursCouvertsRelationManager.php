<?php

declare(strict_types=1);

namespace App\Filament\Resources\EngagementPriseEnChargeResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource;
use App\Models\DeblocageAcces;
use App\Models\EngagementPriseEnCharge;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\SuiviEngagement;
use App\Support\BelongsToModule;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditeurs rattachés à l'engagement (lot A2, §9.10), un lien par ligne, révoqués compris :
 * couvert et reste dans la portée, réglé par l'organisme, état de l'accès anticipé.
 * Lecture seule ; la révocation se fait sur la fiche de l'auditeur (responsable).
 */
#[BelongsToModule('scolarite')]
class AuditeursCouvertsRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'deblocages';

    protected static ?string $title = 'Auditeurs couverts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows()
            && $ownerRecord instanceof EngagementPriseEnCharge
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    /** @var array<int, int>|null réglé par l'organisme, par auditeur */
    private ?array $regles = null;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'inscription' => fn ($i) => $i->with(['bareme', 'auditeur.candidature:id,nom,prenom,matricule'])->withSum('imputationsActives', 'montant')->withSum('imputationsConfirmees', 'montant'),
            ]))
            ->defaultSort('id')
            ->paginated([25, 50, 100])
            ->columns([
                // Colonne calculée : une colonne « inscription.auditeur… » ferait recharger l'inscription
                // sans son barème ni son imputé (une requête par ligne).
                Tables\Columns\TextColumn::make('matricule')->label('Matricule')->fontFamily('mono')
                    ->getStateUsing(fn (DeblocageAcces $record): ?string => $record->inscription?->auditeur?->candidature?->matricule),
                Tables\Columns\TextColumn::make('nom')->label('Nom')
                    ->getStateUsing(fn (DeblocageAcces $record): string => trim(mb_strtoupper((string) $record->inscription?->auditeur?->candidature?->nom)
                        .' '.$record->inscription?->auditeur?->candidature?->prenom)),
                Tables\Columns\TextColumn::make('couvert')->label('Couvert')->alignEnd()
                    ->getStateUsing(fn (DeblocageAcces $record): string => $record->statut === DeblocageAcces::REVOQUE ? '—' : LibelleSituation::fcfa($this->portee($record)[0])),
                Tables\Columns\TextColumn::make('regle')->label('Réglé par l\'organisme')->alignEnd()
                    ->getStateUsing(fn (DeblocageAcces $record): string => LibelleSituation::fcfa($this->regles()[(int) $record->inscription?->auditeur_id] ?? 0)),
                Tables\Columns\TextColumn::make('reste')->label('Reste')->alignEnd()->weight('bold')
                    ->getStateUsing(fn (DeblocageAcces $record): string => $record->statut === DeblocageAcces::REVOQUE ? '—' : LibelleSituation::fcfa($this->portee($record)[1])),
                Tables\Columns\TextColumn::make('statut')->label('Accès anticipé')->badge()
                    ->formatStateUsing(fn (string $state): string => DeblocageAcces::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        DeblocageAcces::EN_COURS => 'warning',
                        DeblocageAcces::REGULARISE => 'success',
                        default => 'gray',
                    })
                    ->description(fn (DeblocageAcces $record): string => $record->estEnCours() ? 'jusqu\'au '.$record->echeance->format('d/m/Y')
                        : 'le '.$record->clos_le?->timezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y')),
            ])
            ->recordUrl(fn (DeblocageAcces $record): ?string => $record->inscription?->auditeur !== null && AuditeurResource::canView($record->inscription->auditeur)
                ? AuditeurResource::getUrl('view', ['record' => $record->inscription->auditeur])
                : null)
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    /** @return array{0: int, 1: int} couvert et reste dans la portée de l'engagement */
    private function portee(DeblocageAcces $d): array
    {
        /** @var EngagementPriseEnCharge $e */
        $e = $this->getOwnerRecord();

        return $d->inscription === null ? [0, 0] : SuiviEngagement::portee($e->portee, $d->inscription);
    }

    /** @return array<int, int> versements valides des règlements valides de l'engagement, par auditeur (une requête) */
    private function regles(): array
    {
        return $this->regles ??= Versement::query()
            ->whereNull('annule_at')
            ->whereHas('reglement', fn (Builder $r) => $r->where('engagement_id', $this->getOwnerRecord()->getKey())->whereNull('annule_at'))
            ->groupBy('auditeur_id')->selectRaw('auditeur_id, SUM(montant) AS total')
            ->pluck('total', 'auditeur_id')->map(fn ($t): int => (int) $t)->all();
    }
}
