<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\MaClasseResource\Pages;
use App\Models\Auditeur;
use App\Models\InscriptionAnnuelle;
use App\Services\Lmd\PerimetreCours;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * « Ma classe » (spec lot 16b, § 2.7) : auditeurs des classes du point focal, en lecture seule.
 * Ni action, ni fiche, ni finances, ni coordonnées : seulement de quoi tenir une feuille de présence.
 */
#[BelongsToModule('lmd')]
class MaClasseResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = InscriptionAnnuelle::class;

    protected static ?string $slug = 'ma-classe';

    protected static ?string $modelLabel = 'Auditeur';

    protected static ?string $pluralModelLabel = 'Ma classe';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Ma classe';

    protected static ?string $navigationGroup = GroupeNavigation::PLANIFICATION;

    protected static ?int $navigationSort = 49;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(PermissionsLmd::POINT_FOCAL);
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
        $user = auth()->user();

        return ($user === null ? InscriptionAnnuelle::query()->whereKey([]) : PerimetreCours::inscriptions($user))
            ->with(['auditeur.candidature:id,matricule,nom,prenom', 'parcours']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('auditeur.candidature.matricule')->label('Matricule')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('auditeur.candidature.nom')->label('Nom')->searchable()
                    ->formatStateUsing(fn (?string $state): string => mb_strtoupper((string) $state)),
                Tables\Columns\TextColumn::make('auditeur.candidature.prenom')->label('Prénoms')->searchable(),
                Tables\Columns\TextColumn::make('classe')->label('Classe')
                    ->getStateUsing(fn (InscriptionAnnuelle $i): string => $i->annee_academique.' · '.($i->parcours?->libelleComplet() ?? $i->niveau)),
                Tables\Columns\TextColumn::make('auditeur.statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (?string $state): string => Auditeur::STATUTS[$state] ?? (string) $state),
            ])
            ->recordUrl(null)
            ->paginated([50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMaClasse::route('/')];
    }
}
