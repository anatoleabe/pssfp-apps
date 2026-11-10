<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Forms\Components\PrivateFileUpload;
use App\Filament\Resources\MessageResource\Pages;
use App\Models\Message;
use App\Models\MessageDestinataire;
use App\Models\PieceMessage;
use App\Models\User;
use App\Services\Messagerie\Messagerie;
use App\Support\BelongsToModule;
use App\Support\ModuleAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot 14b-1 (D31) : messagerie interne du personnel. Chacun ne voit que les messages qu'il a envoyés ou reçus.
 */
#[BelongsToModule(ModuleAccess::CORE)]
class MessageResource extends Resource
{
    use ChecksModuleAccess;

    protected static ?string $model = Message::class;

    protected static ?string $modelLabel = 'message';

    protected static ?string $pluralModelLabel = 'Messagerie';

    protected static ?string $navigationLabel = 'Messagerie';

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = -5;

    protected static ?string $slug = 'messagerie';

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
        $user = auth()->user();

        return static::canAccess() && $record instanceof Message && $user instanceof User && app(Messagerie::class)->peutVoir($user, $record);
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
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
        $n = app(Messagerie::class)->nonLus($user);

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Messages non lus';
    }

    /** Messages envoyés ou reçus par l'utilisateur, avec son état de lecture et d'archivage. */
    public static function getEloquentQuery(): Builder
    {
        $id = (int) auth()->id();
        $mien = fn (string $colonne) => MessageDestinataire::query()->select($colonne)
            ->whereColumn('messages_destinataires.message_id', 'messages.id')->where('messages_destinataires.user_id', $id)->limit(1);

        return parent::getEloquentQuery()
            ->where(fn (Builder $q) => $q->where('expediteur_id', $id)
                ->orWhereHas('destinataires', fn (Builder $d) => $d->where('user_id', $id)))
            ->addSelect(['mon_lu_le' => $mien('lu_le'), 'mon_archive_le' => $mien('archive_le')])
            ->withCount('pieces')
            ->with(['expediteur:id,name', 'destinataires.user:id,name']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\Select::make('destinataires')->label('Destinataires')->multiple()->searchable()->required()
                    ->options(fn (): array => Messagerie::personnel()->whereKeyNot((int) auth()->id())->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Comptes du personnel.'),
                Forms\Components\TextInput::make('objet')->label('Objet')->required()->maxLength(Message::OBJET_MAX),
                Forms\Components\Select::make('priorite')->label('Priorité')->options(Message::PRIORITES)->default(Message::NORMALE)->required()->native(false),
                Forms\Components\Textarea::make('corps')->label('Message')->required()->rows(10)->maxLength(Message::CORPS_MAX),
                ...self::champsEnvoi(),
            ]),
        ])->columns(1);
    }

    /** Pièces jointes et copie par courriel : communs au nouveau message et à la réponse. */
    public static function champsEnvoi(): array
    {
        return [
            // Champ protégé (chemin falsifié refusé) ; le fichier reste temporaire : le service le contrôle puis le range.
            PrivateFileUpload::make('pieces')->label('Pièces jointes')->multiple()->storeFiles(false)
                ->disk(PieceMessage::DISQUE)->directory(PieceMessage::DOSSIER)
                ->acceptedFileTypes(array_keys(PieceMessage::TYPES))->maxSize((int) (PieceMessage::TAILLE_MAX / 1024))
                ->maxFiles(PieceMessage::NOMBRE_MAX)
                ->helperText('PDF, JPEG, PNG, Excel ou Word ; 10 Mo par fichier, 5 fichiers au plus.'),
            Forms\Components\Toggle::make('copie_courriel')->label('Prévenir aussi par courriel')->default(true)
                ->helperText('Le courriel ne porte que l\'objet et le lien : le message reste dans la plateforme.'),
        ];
    }

    /**
     * Pièces déposées par le formulaire, telles quelles : le service refuse tout ce qui n'est pas un fichier déposé.
     *
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    public static function piecesDuFormulaire(array $data): array
    {
        return array_values((array) ($data['pieces'] ?? []));
    }

    public static function table(Table $table): Table
    {
        $nonLu = fn (Message $record): bool => $record->expediteur_id !== auth()->id() && $record->getAttribute('mon_lu_le') === null;

        return $table
            ->columns([
                Tables\Columns\IconColumn::make('etat')->label('')->state(fn (Message $record): bool => $nonLu($record))
                    ->icon(fn (bool $state): string => $state ? 'heroicon-s-envelope' : 'heroicon-o-envelope-open')
                    ->color(fn (bool $state): string => $state ? 'primary' : 'gray')
                    ->tooltip(fn (bool $state): string => $state ? 'Non lu' : 'Lu'),
                Tables\Columns\TextColumn::make('expediteur.name')->label('De')->default('Système'),
                Tables\Columns\TextColumn::make('pour')->label('À')->limit(40)
                    ->state(fn (Message $record): string => $record->destinataires->map(fn ($d) => $d->user?->name)->filter()->join(', ')),
                Tables\Columns\TextColumn::make('objet')->label('Objet')->searchable()->limit(70)
                    ->weight(fn (Message $record): ?FontWeight => $nonLu($record) ? FontWeight::Bold : null),
                Tables\Columns\TextColumn::make('categorie')->label('Catégorie')->badge()
                    ->formatStateUsing(fn (string $state): string => Message::CATEGORIES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === Message::MESSAGE ? 'gray' : 'info'),
                Tables\Columns\TextColumn::make('priorite')->label('Priorité')->badge()
                    ->formatStateUsing(fn (string $state): string => Message::PRIORITES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Message::URGENTE => 'danger', Message::HAUTE => 'warning', default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('pieces_count')->label('')->state(fn (Message $record): bool => (int) $record->getAttribute('pieces_count') > 0)
                    ->icon(fn (bool $state): ?string => $state ? 'heroicon-o-paper-clip' : null)->tooltip('Pièces jointes'),
                Tables\Columns\TextColumn::make('envoye_le')->label('Date')->dateTime('d/m/Y H:i', 'Africa/Douala')->sortable(),
            ])
            ->defaultSort('envoye_le', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('categorie')->label('Catégorie')->options(Message::CATEGORIES),
                Tables\Filters\SelectFilter::make('priorite')->label('Priorité')->options(Message::PRIORITES),
            ])
            ->recordUrl(fn (Message $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Aucun message')
            ->emptyStateIcon('heroicon-o-envelope');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessages::route('/'),
            'create' => Pages\CreateMessage::route('/nouveau'),
            'view' => Pages\ViewMessage::route('/{record}'),
        ];
    }
}
