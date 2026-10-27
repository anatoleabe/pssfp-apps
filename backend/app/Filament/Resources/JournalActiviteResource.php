<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\JournalActiviteResource\Pages;
use App\Models\Candidature;
use App\Models\User;
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
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Journal d'activité de toute l'application : qui a fait quoi, quand, sur
 * quel élément. Lecture seule, réservé à l'administration (journal.view).
 */
#[BelongsToModule('administration')]
class JournalActiviteResource extends Resource
{
    use ChecksModuleAccess;

    public const PERMISSION = 'journal.view';

    /** Heure affichée : celle de Yaoundé (les dates sont stockées en UTC). */
    public const FUSEAU = 'Africa/Douala';

    /** Journaux connus → libellé lisible. */
    public const JOURNAUX = [
        'admin' => 'Modifications back-office',
        'connexions' => 'Connexions du personnel',
        'candidatures' => 'Candidatures',
        'auth.candidat' => 'Connexions des candidats',
        'default' => 'Comptes, rôles et bibliothèque',
        'roles' => 'Rôles',
        'envois' => 'Envois SMS / e-mail',
        'parametres' => 'Paramètres',
        'documents' => 'Bibliothèque',
    ];

    protected static ?string $model = Activity::class;

    protected static ?string $modelLabel = 'Entrée du journal';

    protected static ?string $pluralModelLabel = 'Journal d\'activité';

    protected static ?string $navigationLabel = 'Journal d\'activité';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'journal-activite';

    public static function canAccess(): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can(self::PERMISSION);
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['causer', 'subject']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(50)
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s', self::FUSEAU)
                    ->sortable(),
                Tables\Columns\TextColumn::make('auteur')
                    ->label('Auteur')
                    ->state(fn (Activity $r): string => self::auteur($r))
                    ->description(fn (Activity $r): ?string => $r->causer instanceof User ? $r->causer->email : null)
                    ->wrap(),
                Tables\Columns\TextColumn::make('log_name')
                    ->label('Domaine')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::JOURNAUX[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'connexions', 'auth.candidat' => 'gray',
                        'admin' => 'info',
                        'candidatures' => 'success',
                        'envois' => 'warning',
                        default => 'primary',
                    }),
                Tables\Columns\TextColumn::make('description')
                    ->label('Action')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('element')
                    ->label('Élément')
                    ->state(fn (Activity $r): string => self::element($r))
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('Domaine')
                    ->multiple()
                    ->options(self::JOURNAUX),
                Tables\Filters\SelectFilter::make('event')
                    ->label('Type d\'action')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                Tables\Filters\Filter::make('auteur')
                    ->form([
                        Forms\Components\Select::make('causer_id')
                            ->label('Auteur')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $recherche): array => User::query()
                                ->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$recherche}%")->orWhere('email', 'ilike', "%{$recherche}%"))
                                ->limit(50)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($valeur): ?string => User::query()->find($valeur)?->name),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['causer_id'] ?? null, fn (Builder $q, $id) => $q
                        ->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id)))
                    ->indicateUsing(fn (array $data): ?string => ($data['causer_id'] ?? null) ? 'Auteur : '.User::query()->find($data['causer_id'])?->name : null),
                Tables\Filters\TernaryFilter::make('personnel')
                    ->label('Auteur')
                    ->placeholder('Tout le monde')
                    ->trueLabel('Personnel du back-office')
                    ->falseLabel('Candidats et système')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('causer_type', (new User)->getMorphClass())
                            ->whereIn('causer_id', User::query()->has('roles')->select('id')),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->whereNull('causer_id')
                            ->orWhereNotIn('causer_id', User::query()->has('roles')->select('id'))),
                    ),
                Tables\Filters\Filter::make('periode')
                    ->form([
                        Forms\Components\DatePicker::make('du')->label('Du')->native(false)->displayFormat('d/m/Y'),
                        Forms\Components\DatePicker::make('au')->label('Au')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['du'] ?? null, fn (Builder $q, $du) => $q->where('created_at', '>=', Carbon::parse($du, self::FUSEAU)->startOfDay()->utc()))
                        ->when($data['au'] ?? null, fn (Builder $q, $au) => $q->where('created_at', '<=', Carbon::parse($au, self::FUSEAU)->endOfDay()->utc())))
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
                Infolists\Components\TextEntry::make('created_at')->label('Date')->dateTime('d/m/Y H:i:s', self::FUSEAU),
                Infolists\Components\TextEntry::make('auteur')->label('Auteur')->state(fn (Activity $r): string => self::auteur($r)),
                Infolists\Components\TextEntry::make('log_name')->label('Domaine')
                    ->formatStateUsing(fn (?string $state): string => self::JOURNAUX[$state] ?? (string) $state),
                Infolists\Components\TextEntry::make('event')->label('Type d\'action')->placeholder('—'),
                Infolists\Components\TextEntry::make('description')->label('Action')->columnSpanFull(),
                Infolists\Components\TextEntry::make('element')->label('Élément')->state(fn (Activity $r): string => self::element($r))->columnSpanFull(),
                Infolists\Components\TextEntry::make('details')->label('Détails enregistrés')
                    ->state(fn (Activity $r): string => $r->properties->isEmpty() ? '—'
                        : (string) json_encode($r->properties->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs'])
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalActivite::route('/'),
        ];
    }

    private static function auteur(Activity $r): string
    {
        if ($r->causer instanceof User) {
            return (string) $r->causer->name;
        }

        return $r->causer_id === null ? 'Système' : 'Compte supprimé';
    }

    private static function element(Activity $r): string
    {
        if ($r->subject_type === null) {
            return '—';
        }
        $type = class_basename($r->subject_type);
        $repere = match (true) {
            $r->subject instanceof Candidature => $r->subject->numero_dossier,
            is_string($r->properties->get('element')) => $r->properties->get('element'),
            $r->subject instanceof User => $r->subject->name,
            default => '#'.$r->subject_id,
        };

        return Str::of($type)->headline().' · '.$repere;
    }
}
