<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Models\Auditeur;
use App\Models\Versement;
use App\Services\Scolarite\LibelleSituation;
use App\Support\BelongsToModule;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Historique complet des versements, lignes annulées comprises et barrées. */
#[BelongsToModule('scolarite')]
class VersementsRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'versements';

    protected static ?string $title = 'Versements';

    protected static ?string $icon = 'heroicon-o-banknotes';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows()
            && $ownerRecord instanceof Auditeur
            && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    /** Sur la page « Voir », les actions de la Scolarité restent disponibles. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['saisiPar', 'annulePar', 'inscription']))
            ->defaultSort('date_versement', 'desc')
            ->recordTitleAttribute('reference')
            ->columns([
                Tables\Columns\TextColumn::make('date_versement')->label('Date')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('montant')->label('Montant')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state))
                    ->extraAttributes(fn (Versement $record): array => $record->estAnnule() ? ['class' => 'line-through opacity-60'] : []),
                Tables\Columns\TextColumn::make('mode')->label('Mode')->formatStateUsing(fn (string $state): string => Versement::libelleMode($state)),
                Tables\Columns\TextColumn::make('reference')->label('Référence')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('payeur')->label('Payé par')->formatStateUsing(fn (string $state): string => Versement::PAYEURS[$state] ?? $state),
                Tables\Columns\TextColumn::make('inscription.annee_academique')->label('Année'),
                Tables\Columns\TextColumn::make('saisiPar.name')->label('Saisi par')->placeholder('—'),
                Tables\Columns\TextColumn::make('etat')->label('État')->badge()
                    ->getStateUsing(fn (Versement $record): string => $record->estAnnule() ? 'Annulé' : 'Valide')
                    ->color(fn (Versement $record): string => $record->estAnnule() ? 'gray' : 'success')
                    ->tooltip(fn (Versement $record): ?string => $record->estAnnule()
                        ? 'Annulé le '.$record->annule_at->timezone('Africa/Douala')->format('d/m/Y').' par '.($record->annulePar?->name ?? '—').' : '.$record->annule_motif
                        : null),
            ])
            ->headerActions([])
            ->actions([
                AuditeurActions::voirJustificatif(),
                AuditeurActions::ajouterJustificatif(),
                AuditeurActions::annulerVersement(),
            ])
            ->bulkActions([]);
    }
}
