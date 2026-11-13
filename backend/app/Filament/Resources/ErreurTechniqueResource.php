<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\ErreurTechniqueResource\Pages;
use App\Models\ErreurTechnique;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Erreurs techniques : pannes de l'API, du back-office, des tâches de fond et
 * écrans d'erreur du portail apply. Lecture seule, même droit que le journal
 * d'activité (journal.view). Purgées après 90 jours.
 */
#[BelongsToModule('administration')]
class ErreurTechniqueResource extends Resource
{
    use ChecksModuleAccess;

    protected static ?string $model = ErreurTechnique::class;

    protected static ?string $modelLabel = 'Erreur technique';

    protected static ?string $pluralModelLabel = 'Erreurs techniques';

    protected static ?string $navigationLabel = 'Erreurs techniques';

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = GroupeNavigation::ADMINISTRATION;

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'erreurs-techniques';

    public static function canAccess(): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can(JournalActiviteResource::PERMISSION);
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
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

    /** Nombre d'erreurs des dernières 24 heures, affiché dans le menu. */
    public static function getNavigationBadge(): ?string
    {
        $nombre = ErreurTechnique::query()->where('created_at', '>=', now()->subDay())->count();

        return $nombre > 0 ? (string) $nombre : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Erreurs des dernières 24 heures';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(50)
            ->paginationPageOptions([25, 50, 100])
            ->poll('60s')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s', JournalActiviteResource::FUSEAU)
                    ->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Où')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ErreurTechnique::SOURCES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        ErreurTechnique::SOURCE_APPLY => 'warning',
                        ErreurTechnique::SOURCE_ADMIN => 'info',
                        ErreurTechnique::SOURCE_TACHES => 'gray',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('message')
                    ->label('Erreur')
                    ->limit(140)
                    ->wrap()
                    ->description(fn (ErreurTechnique $r): ?string => $r->type)
                    ->searchable(),
                Tables\Columns\TextColumn::make('page')
                    ->label('Page')
                    ->description(fn (ErreurTechnique $r): ?string => $r->methode)
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Compte')
                    ->placeholder('Visiteur ou système'),
                Tables\Columns\TextColumn::make('appareil')
                    ->label('Appareil')
                    ->state(fn (ErreurTechnique $r): string => ErreurTechnique::appareil($r->navigateur)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->label('Où')
                    ->multiple()
                    ->options(ErreurTechnique::SOURCES),
                Tables\Filters\Filter::make('periode')
                    ->form([
                        Forms\Components\DatePicker::make('du')->label('Du')->native(false)->displayFormat('d/m/Y'),
                        Forms\Components\DatePicker::make('au')->label('Au')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['du'] ?? null, fn (Builder $q, $du) => $q->where('created_at', '>=', Carbon::parse($du, JournalActiviteResource::FUSEAU)->startOfDay()->setTimezone(config('app.timezone'))))
                        ->when($data['au'] ?? null, fn (Builder $q, $au) => $q->where('created_at', '<=', Carbon::parse($au, JournalActiviteResource::FUSEAU)->endOfDay()->setTimezone(config('app.timezone')))))
                    ->indicateUsing(fn (array $data): ?string => ($data['du'] ?? null) || ($data['au'] ?? null)
                        ? 'Période : '.($data['du'] ?? '…').' → '.($data['au'] ?? '…') : null),
            ])
            ->filtersFormColumns(2)
            ->actions([
                Tables\Actions\ViewAction::make()->label('Détail'),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(2)->schema([
                Infolists\Components\TextEntry::make('created_at')->label('Date')->dateTime('d/m/Y H:i:s', JournalActiviteResource::FUSEAU),
                Infolists\Components\TextEntry::make('source')->label('Où')
                    ->formatStateUsing(fn (string $state): string => ErreurTechnique::SOURCES[$state] ?? $state),
                Infolists\Components\TextEntry::make('type')->label('Type')->placeholder('—'),
                Infolists\Components\TextEntry::make('user.name')->label('Compte')->placeholder('Visiteur ou système'),
                Infolists\Components\TextEntry::make('message')->label('Erreur')->columnSpanFull()
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs']),
                Infolists\Components\TextEntry::make('page')->label('Page')->placeholder('—')->columnSpanFull(),
                Infolists\Components\TextEntry::make('methode')->label('Méthode')->placeholder('—'),
                Infolists\Components\TextEntry::make('emplacement')->label('Emplacement dans le code')->placeholder('—'),
                Infolists\Components\TextEntry::make('appareil')->label('Appareil')
                    ->state(fn (ErreurTechnique $r): string => ErreurTechnique::appareil($r->navigateur)),
                Infolists\Components\TextEntry::make('ip')->label('Adresse IP')->placeholder('—'),
                Infolists\Components\TextEntry::make('requete')->label('Identifiant de requête (journal fichier)')->placeholder('—'),
                Infolists\Components\TextEntry::make('digest')->label('Repère Next.js (digest)')->placeholder('—'),
                Infolists\Components\TextEntry::make('navigateur')->label('Navigateur (en-tête complet)')->placeholder('—')->columnSpanFull()
                    ->extraAttributes(['class' => 'font-mono text-xs']),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListErreursTechniques::route('/'),
        ];
    }
}
