<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\InscriptionResource\Pages;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\InscriptionPedagogique;
use App\Models\Parcours;
use App\Services\Scolarite\AnneeEnCours;
use App\Support\BelongsToModule;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * « Inscriptions » (spec lot 18, règle 4) : les inscriptions annuelles, année par année, en lecture
 * seule. Une inscription se crée et se corrige depuis la fiche de l'auditeur ; le passage en groupe
 * et le changement de l'année en cours se lancent d'ici.
 */
#[BelongsToModule('scolarite')]
class InscriptionResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = InscriptionAnnuelle::class;

    protected static ?string $slug = 'inscriptions';

    protected static ?string $modelLabel = 'Inscription';

    protected static ?string $pluralModelLabel = 'Inscriptions';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = GroupeNavigation::SCOLARITE;

    protected static ?int $navigationSort = 15;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('view_any_auditeur');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return InscriptionAnnuelle::query()
            ->with(['auditeur.candidature:id,matricule,nom,prenom', 'parcours'])
            ->withCount([
                'inscriptionsPedagogiques as ues_count',
                'inscriptionsPedagogiques as ues_capitalisees_count' => fn (Builder $q) => $q->where('statut', InscriptionPedagogique::CAPITALISEE),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('auditeur.candidature.matricule')->label('Matricule')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('auditeur.candidature.nom')->label('Nom')->searchable()
                    ->formatStateUsing(fn (?string $state): string => mb_strtoupper((string) $state)),
                Tables\Columns\TextColumn::make('auditeur.candidature.prenom')->label('Prénoms')->searchable(),
                Tables\Columns\TextColumn::make('annee_academique')->label('Année'),
                Tables\Columns\TextColumn::make('niveau')->label('Niveau')->badge(),
                Tables\Columns\TextColumn::make('parcours.libelle')->label('Parcours')->placeholder('Non posé'),
                Tables\Columns\TextColumn::make('ues_count')->label('UE')
                    ->formatStateUsing(fn (int $state, InscriptionAnnuelle $record): string => $state === 0 ? 'Aucune'
                        : $state.($record->ues_capitalisees_count > 0 ? ' dont '.$record->ues_capitalisees_count.' capitalisée'.($record->ues_capitalisees_count > 1 ? 's' : '') : ''))
                    ->color(fn (int $state): ?string => $state === 0 ? 'warning' : null),
                Tables\Columns\TextColumn::make('auditeur.statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (?string $state): string => Auditeur::STATUTS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => $state === Auditeur::STATUT_ACTIF ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('annee_academique')->label('Année')->native(false)
                    ->options(fn (): array => InscriptionAnnuelle::query()->distinct()->orderByDesc('annee_academique')->pluck('annee_academique', 'annee_academique')->all())
                    ->default(fn (): ?string => AnneeEnCours::annee()),
                Tables\Filters\SelectFilter::make('niveau')->label('Niveau')->native(false)->options(BaremeScolarite::NIVEAUX),
                Tables\Filters\SelectFilter::make('parcours_id')->label('Parcours')->native(false)
                    ->options(fn (): array => Parcours::query()->orderBy('ordre')->get()->mapWithKeys(fn (Parcours $p): array => [$p->id => $p->libelleComplet()])->all()),
                Tables\Filters\Filter::make('sans_ue')->label('Sans UE')->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('inscriptionsPedagogiques')),
            ])
            ->recordUrl(fn (InscriptionAnnuelle $record): ?string => $record->auditeur !== null && auth()->user()?->can('view_auditeur')
                ? AuditeurResource::getUrl('view', ['record' => $record->auditeur]) : null)
            ->paginated([50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInscriptions::route('/')];
    }
}
