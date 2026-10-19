<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Models\Document;
use App\Support\BelongsToModule;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * « Anciennes adresses » de la fiche : redirections de l'ancien site dont la
 * cible est `/documents/{slug}`, avec leur nombre de visites. Lecture seule ;
 * leur gestion relève de l'écran des redirections (`library.redirects`).
 * Réservé aux comptes qui consultent le catalogue (pas aux contributeurs).
 */
#[BelongsToModule('bibliotheque')]
class LegacyRedirectsRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'legacyRedirects';

    protected static ?string $title = 'Anciennes adresses';

    protected static ?string $icon = 'heroicon-o-arrow-uturn-right';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return static::moduleAllows()
            && $ownerRecord instanceof Document
            && (bool) $user?->can('view_any_document')
            && (bool) $user?->can('view', $ownerRecord);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('from_path')->label('Ancienne adresse')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('hits')->label('Visites')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Dernière mise à jour')->dateTime('d/m/Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('hits', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Aucune ancienne adresse ne mène à cette fiche');
    }
}
