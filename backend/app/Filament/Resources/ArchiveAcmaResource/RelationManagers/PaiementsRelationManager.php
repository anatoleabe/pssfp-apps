<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArchiveAcmaResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Models\AcmaDossier;
use App\Models\AcmaPaiement;
use App\Services\Scolarite\LibelleSituation;
use App\Support\BelongsToModule;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lignes de paiement d'ACMA (lot 15, §7.7), en lecture seule : un historique, jamais des versements. Les anomalies
 * sont signalées telles qu'ACMA les porte, jamais corrigées.
 */
#[BelongsToModule('archives')]
class PaiementsRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'paiements';

    protected static ?string $title = 'Paiements ACMA (détail)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows() && $ownerRecord instanceof AcmaDossier && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    public function table(Table $table): Table
    {
        $fcfa = fn (string $colonne) => Tables\Columns\TextColumn::make($colonne)->alignEnd()->formatStateUsing(fn ($state): string => LibelleSituation::fcfa((int) $state));

        return $table
            ->defaultSort('annee')
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('date_recu')->orderBy('id_acma'))
            ->columns([
                Tables\Columns\TextColumn::make('annee')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('niveau_acma')->label('Niveau'),
                Tables\Columns\TextColumn::make('date_recu')->label('Reçu du')
                    ->getStateUsing(fn (AcmaPaiement $record): string => $record->date_recu?->format('d/m/Y') ?? ($record->date_recu_source ?? '—')),
                Tables\Columns\TextColumn::make('numero_recu')->label('N° de reçu')->placeholder('—'),
                Tables\Columns\TextColumn::make('banque')->label('Banque')->placeholder('—'),
                Tables\Columns\TextColumn::make('phase')->label('Phase')->wrap()->placeholder('—'),
                $fcfa('montant_scolarite')->label('Scolarité'),
                $fcfa('inscriptions')->label('Inscription'),
                Tables\Columns\TextColumn::make('annexes')->label('Annexes')->alignEnd()
                    ->getStateUsing(fn (AcmaPaiement $record): string => LibelleSituation::fcfa((int) $record->bibliotheque + (int) $record->fraisperiscol + (int) $record->tenuesport)),
                $fcfa('total')->label('Total')->weight('bold'),
                Tables\Columns\TextColumn::make('drapeaux')->label('Signalements')->badge()->color('warning')
                    ->getStateUsing(fn (AcmaPaiement $record): array => $record->drapeaux()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('annee')->label('Année')
                    ->options(fn (): array => $this->getOwnerRecord()->paiements()->distinct()->orderBy('annee')->pluck('annee', 'annee')->all()),
                Tables\Filters\Filter::make('signalees')->label('Lignes signalées')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $w) => $w->where('negatif', true)->orWhere('total_nul', true)
                        ->orWhere('date_aberrante', true)->orWhere('hors_annee', true)->orWhere('doublon_exact', true)->orWhere('niveau_sans_inscription', true))),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
