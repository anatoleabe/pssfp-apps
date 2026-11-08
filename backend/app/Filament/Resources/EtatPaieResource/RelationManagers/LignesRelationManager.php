<?php

declare(strict_types=1);

namespace App\Filament\Resources\EtatPaieResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Models\LigneEtatPaie;
use App\Models\PointageEnseignant;
use App\Services\Lmd\EtatsPaie;
use App\Support\BelongsToModule;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Lignes d'un état de paie (lecture seule) : une par enseignant, ECUE et nature. */
#[BelongsToModule('lmd')]
class LignesRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'lignes';

    protected static ?string $title = 'Lignes de l\'état';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        $h = fn (float $x): string => rtrim(rtrim(number_format($x, 2, ',', ' '), '0'), ',');

        return $table
            ->modifyQueryUsing(fn (Builder $q): Builder => $q->with(['enseignant', 'ecue']))
            ->defaultSort('enseignant_id')
            ->columns([
                Tables\Columns\TextColumn::make('enseignant.name')->label('Enseignant')->searchable(),
                Tables\Columns\TextColumn::make('ecue.code')->label('Cours')->description(fn (LigneEtatPaie $l): ?string => $l->ecue?->intitule)->wrap(),
                Tables\Columns\TextColumn::make('nature')->label('Nature')->formatStateUsing(fn (string $state): string => $state === EtatsPaie::PRIME ? 'Prime d\'animation' : (PointageEnseignant::NATURES[$state] ?? $state)),
                Tables\Columns\TextColumn::make('quantite')->label('Quantité')->alignEnd()
                    ->formatStateUsing(fn (LigneEtatPaie $l): string => match ($l->nature) {
                        'surveillance' => $h($l->quantite).' séance(s)',
                        EtatsPaie::PRIME => $h(round($l->quantite * 100, 2)).' % de la prime',
                        default => $h($l->quantite).' h',
                    })
                    ->description(fn (LigneEtatPaie $l): ?string => $l->heures_hors_quota > 0 ? $h($l->heures_hors_quota).' h hors quota' : null),
                Tables\Columns\TextColumn::make('montant_brut')->label('Brut')->alignEnd()->formatStateUsing(fn (int $state): string => EtatsPaie::fcfa($state))
                    ->description(fn (LigneEtatPaie $l): string => EtatsPaie::fcfa($l->montant_unitaire).' × '
                        .($l->nature === EtatsPaie::PRIME ? $h(round($l->quantite * 100, 2)).' %' : $h($l->quantite))),
                Tables\Columns\TextColumn::make('montant_retenue')->label('Retenue')->alignEnd()->formatStateUsing(fn (int $state): string => EtatsPaie::fcfa($state)),
                Tables\Columns\TextColumn::make('montant_net')->label('Net')->alignEnd()->weight('bold')->formatStateUsing(fn (int $state): string => EtatsPaie::fcfa($state)),
                Tables\Columns\TextColumn::make('observation')->label('Observation')->wrap()->placeholder('—'),
            ])
            ->paginated([25, 50, 100]);
    }
}
