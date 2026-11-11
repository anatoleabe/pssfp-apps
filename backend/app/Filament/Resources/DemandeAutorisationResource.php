<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\DemandeAutorisationResource\Pages;
use App\Models\DecisionDemande;
use App\Models\DemandeAutorisation;
use App\Models\User;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Support\BelongsToModule;
use App\Support\ModuleAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot 14c (D31) : demandes d'autorisation. Chacun voit ses demandes et celles qu'il peut décider ;
 * le contrôle interne (supervision.consulter) les voit toutes.
 */
#[BelongsToModule(ModuleAccess::CORE)]
class DemandeAutorisationResource extends Resource
{
    use ChecksModuleAccess;

    protected static ?string $model = DemandeAutorisation::class;

    protected static ?string $modelLabel = 'demande';

    protected static ?string $pluralModelLabel = 'Demandes d\'autorisation';

    protected static ?string $navigationLabel = 'Demandes';

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?int $navigationSort = -4;

    protected static ?string $slug = 'demandes';

    protected static ?string $recordTitleAttribute = 'numero';

    public static function canAccess(): bool
    {
        return static::moduleAllows() && auth()->user() instanceof User;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess() && static::getEloquentQuery()->whereKey($record->getKey())->exists();
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return static::canAccess() && $user instanceof User && TypesDemande::libellesPour($user) !== [];
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

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }
        $n = app(Demandes::class)->aDecider($user)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Demandes à décider';
    }

    /** Mes demandes, celles que je peux décider ou que j'ai décidées ; toutes pour le contrôle interne. */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return parent::getEloquentQuery()->whereKey([]);
        }

        return parent::getEloquentQuery()->whereIn('id', app(Demandes::class)->visiblesPar($user)->select('id'))->with(['demandeur:id,name']);
    }

    /** Liste des objets d'un type, seulement pour qui peut viser ce type. */
    private static function objets(Get $get, ?string $terme = null): array
    {
        $type = (string) $get('type');
        $user = auth()->user();

        return $user instanceof User && isset(TypesDemande::TYPES[$type]) && TypesDemande::peutViser($user, $type)
            ? app(TypesDemande::class)->objets($type, $terme) : [];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\Select::make('type')->label('Type de demande')
                    ->options(fn (): array => auth()->user() instanceof User ? TypesDemande::libellesPour(auth()->user()) : [])->required()->native(false)->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('entite_id', null)),
                Forms\Components\Placeholder::make('routage')->label('Qui décide')
                    ->visible(fn (Get $get): bool => filled($get('type')))
                    ->content(fn (Get $get): string => self::routage((string) $get('type'))),
                Forms\Components\Select::make('entite_id')->label('Objet visé')->searchable()->required()
                    ->visible(fn (Get $get): bool => filled($get('type')) && TypesDemande::aUnObjet((string) $get('type')))
                    ->options(fn (Get $get): array => self::objets($get))
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::objets($get, $search))
                    ->getOptionLabelUsing(fn ($value, Get $get): ?string => self::objets($get)[(int) $value] ?? null)
                    ->helperText('Seuls les objets qui se prêtent à ce type sont proposés (au plus 50 ; tapez pour chercher).'),
                Forms\Components\TextInput::make('objet_libre')->label('Objet')->required()->maxLength(300)
                    ->visible(fn (Get $get): bool => $get('type') === TypesDemande::VALIDER_AUTRE),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(DemandeAutorisation::MOTIF_MIN)->maxLength(2000)->rows(5),
            ]),
        ])->columns(1);
    }

    public static function routage(string $type): string
    {
        $t = TypesDemande::TYPES[$type] ?? null;
        if ($t === null) {
            return '';
        }

        return 'Les comptes qui détiennent le droit « '.$t['permission'].' »'
            .($t['visa'] ? ', puis le visa du contrôle interne (une autre personne)' : '')
            .'. Réponse attendue sous '.$t['heures'].' h.';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->fontFamily('mono')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->label('Type')->wrap()
                    ->formatStateUsing(fn (string $state): string => TypesDemande::TYPES[$state]['libelle'] ?? $state),
                Tables\Columns\TextColumn::make('objet')->label('Objet')->limit(60)->searchable(),
                Tables\Columns\TextColumn::make('demandeur.name')->label('Demandeur'),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (string $state): string => DemandeAutorisation::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        DemandeAutorisation::SOUMISE, DemandeAutorisation::VISA_ATTENDU => 'warning',
                        DemandeAutorisation::EXECUTEE => 'success',
                        DemandeAutorisation::REJETEE, DemandeAutorisation::ECHEC => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('soumise_le')->label('Soumise le')->dateTime('d/m/Y H:i', Demandes::FUSEAU)->sortable(),
                Tables\Columns\TextColumn::make('echeance')->label('Échéance')->dateTime('d/m/Y H:i', Demandes::FUSEAU)->sortable()
                    ->color(fn (DemandeAutorisation $record): ?string => $record->enAttente() && $record->echeance->isPast() ? 'danger' : null),
            ])
            ->defaultSort('soumise_le', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->label('Statut')->multiple()->options(DemandeAutorisation::STATUTS),
                Tables\Filters\SelectFilter::make('type')->label('Type')->options(TypesDemande::libelles()),
            ])
            ->recordUrl(fn (DemandeAutorisation $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Aucune demande')
            ->emptyStateIcon('heroicon-o-hand-raised');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(3)->schema([
                Infolists\Components\TextEntry::make('numero')->label('Numéro')->fontFamily('mono'),
                Infolists\Components\TextEntry::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (string $state): string => DemandeAutorisation::STATUTS[$state] ?? $state),
                Infolists\Components\TextEntry::make('type')->label('Type')
                    ->formatStateUsing(fn (string $state): string => TypesDemande::TYPES[$state]['libelle'] ?? $state),
                Infolists\Components\TextEntry::make('objet')->label('Objet')->columnSpan(2),
                Infolists\Components\TextEntry::make('demandeur.name')->label('Demandeur'),
                Infolists\Components\TextEntry::make('motif')->label('Motif')->columnSpanFull(),
                Infolists\Components\TextEntry::make('soumise_le')->label('Soumise le')->dateTime('d/m/Y H:i', Demandes::FUSEAU),
                Infolists\Components\TextEntry::make('echeance')->label('Réponse attendue avant le')->dateTime('d/m/Y H:i', Demandes::FUSEAU),
                Infolists\Components\TextEntry::make('routage')->label('Qui décide')->state(fn (DemandeAutorisation $record): string => self::routage($record->type)),
                Infolists\Components\TextEntry::make('resultat')->label('Résultat')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Décisions')->schema([
                Infolists\Components\RepeatableEntry::make('decisions')->hiddenLabel()->columns(4)->schema([
                    Infolists\Components\TextEntry::make('qualite')->label('Qualité')
                        ->formatStateUsing(fn (string $state): string => DecisionDemande::QUALITES[$state] ?? $state),
                    Infolists\Components\TextEntry::make('decideur.name')->label('Décideur'),
                    Infolists\Components\TextEntry::make('decision')->label('Décision')->badge()
                        ->formatStateUsing(fn (string $state): string => $state === DecisionDemande::APPROUVE ? 'Approuvée' : 'Rejetée')
                        ->color(fn (string $state): string => $state === DecisionDemande::APPROUVE ? 'success' : 'danger'),
                    Infolists\Components\TextEntry::make('decide_le')->label('Le')->dateTime('d/m/Y H:i', Demandes::FUSEAU),
                    Infolists\Components\TextEntry::make('commentaire')->label('Commentaire')->placeholder('—')->columnSpanFull(),
                ])->placeholder('Aucune décision pour l\'instant.'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDemandes::route('/'),
            'create' => Pages\CreateDemande::route('/nouvelle'),
            'view' => Pages\ViewDemande::route('/{record}'),
        ];
    }
}
