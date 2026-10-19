<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Forms\Components\PrivateFileUpload;
use App\Filament\Resources\DocumentResource\DocumentBulkActions;
use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Resources\DocumentResource\RelationManagers;
use App\Models\Document;
use App\Models\User;
use App\Services\Library\DocumentReviewNotifier;
use App\Support\BelongsToModule;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Documents de la bibliothèque virtuelle (spec V1 §5.5).
 *
 * Circuit : brouillon → en revue → publié (validateur tracé) → archivé.
 */
#[BelongsToModule('bibliotheque')]
class DocumentResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Document::class;

    protected static ?string $modelLabel = 'Document';

    protected static ?string $pluralModelLabel = 'Documents';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Module 3 — Bibliothèque';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'slug';

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) ($record->getTranslation('title', 'fr', false) ?: $record->slug);
    }

    /** Minuscules, chiffres et tirets simples (aussi pour le thésaurus). */
    public const SLUG_RULE = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Un brouillon doit d'abord être soumis en revue. */
    public const PUBLISHABLE_STATUSES = [Document::STATUS_IN_REVIEW, Document::STATUS_ARCHIVED];

    public const STATUS_LABELS = [
        Document::STATUS_DRAFT => 'Brouillon',
        Document::STATUS_IN_REVIEW => 'En revue',
        Document::STATUS_PUBLISHED => 'Publié',
        Document::STATUS_ARCHIVED => 'Archivé',
    ];

    /** Motif d'un renvoi en brouillon : transmis au déposant et tracé. */
    public const RETURN_REASON_MAX = 1000;

    public const ACCESS_MODE_LABELS = ['libre' => 'Libre', 'reserve' => 'Réservé', 'payant' => 'Payant'];

    /** Valeurs imposées au dépôt d'un contributeur : l'accès et le prix restent à la bibliothèque. */
    public const CONTRIBUTOR_FORCED = ['access_level' => 'public', 'price_xaf' => null];

    /**
     * Le compte gère-t-il le catalogue (créer et modifier tout document) ?
     * Sinon il ne fait que déposer (`document.contribute`) : formulaire et
     * actions réduits, liste limitée à ses dépôts.
     */
    public static function managesCatalogue(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('create_document') && $user->can('update_document');
    }

    /**
     * Création par un contributeur, quoi que contienne la requête : le
     * formulaire masque l'accès et le prix, le serveur les impose.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforceContributorCreateData(array $data): array
    {
        return self::managesCatalogue() ? $data : [...$data, ...self::CONTRIBUTOR_FORCED];
    }

    /**
     * Modification par un contributeur : l'accès et le prix fixés par la
     * bibliothèque restent tels quels, quoi que contienne la requête.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforceContributorUpdateData(array $data): array
    {
        return self::managesCatalogue() ? $data : Arr::except($data, array_keys(self::CONTRIBUTOR_FORCED));
    }

    /**
     * Sans lecture du catalogue, un compte ne voit que ses dépôts : liste,
     * recherche globale et résolution des adresses de fiche (une adresse
     * forgée vers le dépôt d'un autre aboutit à une 404).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereKey([]);
        }

        return $user->can('view_any_document') ? $query : $query->where('uploaded_by', $user->getKey());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Notice')->columns(2)->schema([
                Forms\Components\Select::make('type')->label('Type')->options(Document::TYPES)->required(),
                Forms\Components\Select::make('language')->label('Langue')->options(Document::LANGUAGES)->default('fr')->required(),
                Forms\Components\TextInput::make('title.fr')->label('Titre')->required()->maxLength(300)->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get, ?string $state) => $get('slug') ? null : $set('slug', rtrim(Str::limit(Str::slug((string) $state), 150, ''), '-'))),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(160)->columnSpanFull()
                    ->rule(self::SLUG_RULE)->validationMessages(['regex' => 'Minuscules, chiffres et tirets simples uniquement (ex. : budget-programme-2024).']),
                Forms\Components\TextInput::make('subtitle.fr')->label('Sous-titre')->maxLength(300)->columnSpanFull(),
                Forms\Components\Textarea::make('abstract.fr')->label('Résumé')->rows(5)->columnSpanFull(),
                Forms\Components\TextInput::make('year')->label('Année')->integer()->minValue(1900)->maxValue(2100),
                Forms\Components\Select::make('specialite')->label('Spécialité')->options((array) config('specialites', []))->searchable(),
                Forms\Components\TextInput::make('promotion')->label('Promotion')->integer()->minValue(1)->maxValue(99),
                Forms\Components\TextInput::make('pages_count')->label('Pages')->integer()->minValue(1),
                Forms\Components\TextInput::make('journal')->label('Revue')->maxLength(200),
                Forms\Components\TextInput::make('doi')->label('DOI')->maxLength(120),
                Forms\Components\TextInput::make('isbn')->label('ISBN')->maxLength(30),
            ]),
            Forms\Components\Section::make('Auteurs et mots-clés')->schema([
                Forms\Components\Repeater::make('authors')->label('Auteurs')->relationship()
                    ->orderColumn('order')
                    ->schema([
                        Forms\Components\TextInput::make('author_name')->label('Nom')->required()->maxLength(200),
                        Forms\Components\TextInput::make('author_affiliation')->label('Affiliation')->maxLength(200),
                    ])->columns(2)->defaultItems(0),
                Forms\Components\Select::make('motsCles')->label('Mots-clés')->relationship('motsCles', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->getTranslation('label', 'fr'))
                    ->multiple()->searchable()->preload(),
            ]),
            Forms\Components\Section::make('Fichier et accès')->columns(2)->schema([
                // Disque privé : seul le fichier déjà enregistré sur la fiche, ou
                // un nouveau dépôt, est admis (cf. PrivateFileUpload).
                PrivateFileUpload::make('file_path')->label('PDF')
                    ->disk((string) config('library.documents_disk'))->directory('documents')
                    ->acceptedFileTypes(['application/pdf'])->maxSize(204800)->columnSpanFull()
                    // Pas de HeadObject en prod : sans ce réglage, Filament croit le
                    // PDF absent à l'ouverture et l'efface à l'enregistrement.
                    ->fetchFileInformation(false),
                Forms\Components\TextInput::make('external_url')->label('Ou lien externe')->url()->rule('url:http,https')->maxLength(500)->columnSpanFull()
                    ->helperText('Sans PDF, sans prix et en accès public : le bouton du site ouvre ce lien.')
                    ->rule(fn (Forms\Get $get, ?Model $record): Closure => self::externalUrlIsExclusive($get, $record)),
                // Accès et prix relèvent de la bibliothèque : absents pour un contributeur.
                Forms\Components\Select::make('access_level')->label('Niveau d\'accès')->options(Document::ACCESS_LEVELS)->default('public')->required()
                    ->visible(fn (): bool => self::managesCatalogue()),
                Forms\Components\TextInput::make('price_xaf')->label('Prix (FCFA)')->integer()->minValue(1)
                    ->visible(fn (): bool => self::managesCatalogue())
                    ->helperText('Non vendu tant que le paiement en ligne n\'est pas actif : le document s\'affiche « Payant — bientôt disponible ».'),
            ]),
        ]);
    }

    /**
     * Fichier ou lien, exclusifs (contrainte reprise en base) : un lien externe
     * n'a ni PDF chez nous, ni prix, ni accès réservé.
     */
    private static function externalUrlIsExclusive(Forms\Get $get, ?Model $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            if (blank($value)) {
                return;
            }

            if (! self::managesCatalogue()) {
                self::checkContributorExternalUrl($get, $record, $fail);

                return;
            }

            if (filled($get('file_path')) || filled($get('price_xaf')) || $get('access_level') !== 'public') {
                $fail('Un lien externe exclut le PDF, le prix et l\'accès réservé : retirez-les ou videz le lien.');
            }
        };
    }

    /**
     * Le contributeur ne voit ni l'accès ni le prix : ceux enregistrés (fixés
     * par la bibliothèque) font foi, avec un message qu'il peut comprendre.
     */
    private static function checkContributorExternalUrl(Forms\Get $get, ?Model $record, Closure $fail): void
    {
        if (filled($get('file_path'))) {
            $fail('Un lien externe exclut le PDF : retirez le PDF ou videz le lien.');

            return;
        }

        if ($record instanceof Document && ($record->price_xaf !== null || $record->access_level !== 'public')) {
            $fail('La bibliothèque a réservé ou tarifé ce document : il ne peut pas renvoyer vers un lien externe.');
        }
    }

    /** Un document se publie avec un PDF ou un lien externe, jamais sans. */
    public static function hasContent(Document $document): bool
    {
        return $document->file_path !== null || $document->external_url !== null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // `title.fr` seul se lit comme une relation : état et recherche explicites.
                Tables\Columns\TextColumn::make('title.fr')->label('Titre')->limit(60)
                    ->getStateUsing(fn (Document $record): string => $record->getTranslation('title', 'fr', false))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('title->fr', 'ilike', '%'.$search.'%')),
                Tables\Columns\TextColumn::make('type')->label('Type')->formatStateUsing(fn (string $state) => Document::TYPES[$state] ?? $state)->badge(),
                Tables\Columns\TextColumn::make('access_mode')->label('Accès')->badge()
                    ->formatStateUsing(fn (string $state) => self::ACCESS_MODE_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'payant' => 'warning', 'reserve' => 'info', default => 'success'
                    }),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Document::STATUS_PUBLISHED => 'success', Document::STATUS_IN_REVIEW => 'warning', Document::STATUS_ARCHIVED => 'danger', default => 'gray'
                    }),
                Tables\Columns\TextColumn::make('year')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('views_count')->label('Vues')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('downloads_count')->label('Téléch.')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(Document::TYPES),
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options(self::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('access_level')->label('Accès')->options(Document::ACCESS_LEVELS),
                Tables\Filters\Filter::make('mes_depots')->label('Mes dépôts')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('uploaded_by', auth()->id())),
                Tables\Filters\TernaryFilter::make('legacy_source')->label('Importé de l\'ancien site')->nullable()
                    ->visible(fn (): bool => self::managesCatalogue()),
                Tables\Filters\TernaryFilter::make('file_path')->label('Avec fichier')->nullable(),
            ])
            ->actions([
                Tables\Actions\Action::make('soumettre')->label('Soumettre en revue')->icon('heroicon-o-paper-airplane')
                    ->authorize(fn (Document $record) => (bool) auth()->user()?->can('update', $record))
                    ->visible(fn (Document $record) => $record->status === Document::STATUS_DRAFT)
                    ->action(fn (Document $record) => self::submit($record)),
                Tables\Actions\Action::make('publier')->label('Publier')->icon('heroicon-o-check-badge')->color('success')
                    ->authorize(fn (Document $record) => (bool) auth()->user()?->can('publish', $record))
                    ->visible(fn (Document $record) => in_array($record->status, self::PUBLISHABLE_STATUSES, true))
                    ->requiresConfirmation()
                    ->action(function (Document $record): void {
                        if (! self::hasContent($record)) {
                            Notification::make()->title('Ajoutez un PDF ou un lien externe avant de publier.')->danger()->send();

                            return;
                        }
                        self::publish($record);
                    }),
                Tables\Actions\Action::make('renvoyerBrouillon')->label('Renvoyer en brouillon')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                    ->authorize(fn () => (bool) auth()->user()?->can('document.publish'))
                    ->visible(fn (Document $record) => $record->status === Document::STATUS_IN_REVIEW)
                    ->modalDescription('Le déposant reçoit ce motif par e-mail ; il est aussi conservé dans l\'historique de la fiche.')
                    ->form([
                        Forms\Components\Textarea::make('motif')->label('Motif')->required()->maxLength(self::RETURN_REASON_MAX)->rows(4),
                    ])
                    ->action(function (Document $record, array $data): void {
                        self::returnToDraft($record, (string) $data['motif']);
                        Notification::make()->title('Document renvoyé en brouillon')->success()->send();
                    }),
                Tables\Actions\Action::make('archiver')->label('Archiver')->icon('heroicon-o-archive-box')->color('gray')
                    ->authorize(fn (Document $record) => (bool) auth()->user()?->can('publish', $record))
                    ->visible(fn (Document $record) => $record->status === Document::STATUS_PUBLISHED)
                    ->requiresConfirmation()
                    ->action(fn (Document $record) => self::archive($record)),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('publierLot')->label('Publier la sélection')->icon('heroicon-o-check-badge')
                    ->authorize(fn () => (bool) auth()->user()?->can('document.publish'))
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $publishable = $records
                            ->filter(fn (Document $d) => in_array($d->status, self::PUBLISHABLE_STATUSES, true) && self::hasContent($d))
                            ->values();
                        // Seule une première validation prévient le déposant (pas une republication).
                        $validated = $publishable->filter(fn (Document $d) => $d->status === Document::STATUS_IN_REVIEW)->values();
                        $publishable->each(fn (Document $d) => self::publish($d, notify: false));
                        DocumentReviewNotifier::published($validated, self::actor());

                        $published = $publishable->count();
                        $skipped = $records->count() - $published;

                        Notification::make()
                            ->title("{$published} document(s) publié(s), {$skipped} ignoré(s)")
                            ->status($published > 0 ? 'success' : 'warning')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                ...DocumentBulkActions::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * Brouillon → en revue ; les validateurs sont prévenus. Le lot passe
     * `notify: false` et regroupe les messages.
     */
    public static function submit(Document $document, bool $notify = true): void
    {
        abort_unless(auth()->user()?->can('update', $document), 403);
        abort_unless($document->status === Document::STATUS_DRAFT, 403);

        $document->update(['status' => Document::STATUS_IN_REVIEW]);

        if ($notify) {
            DocumentReviewNotifier::submitted($document, self::actor());
        }
    }

    /**
     * Publication. Une première validation (depuis « en revue ») prévient le
     * déposant ; le lot passe `notify: false` et regroupe les messages.
     */
    public static function publish(Document $document, bool $notify = true): void
    {
        abort_unless(auth()->user()?->can('document.publish'), 403);
        abort_unless(in_array($document->status, self::PUBLISHABLE_STATUSES, true), 403);
        abort_unless(self::hasContent($document), 422, 'Ajoutez un PDF ou un lien externe avant de publier.');

        $validated = $document->status === Document::STATUS_IN_REVIEW;

        $document->update([
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => $document->published_at ?? now(),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        if ($notify && $validated) {
            DocumentReviewNotifier::published(collect([$document]), self::actor());
        }
    }

    /** En revue → brouillon, avec un motif tracé dans l'historique et transmis au déposant. */
    public static function returnToDraft(Document $document, string $reason): void
    {
        abort_unless(auth()->user()?->can('document.publish'), 403);
        abort_unless($document->status === Document::STATUS_IN_REVIEW, 403);
        abort_if(blank($reason) || mb_strlen($reason) > self::RETURN_REASON_MAX, 422, 'Motif requis (1 000 caractères au plus).');

        $document->update(['status' => Document::STATUS_DRAFT]);

        activity('documents')->causedBy(auth()->user())->performedOn($document)
            ->withProperties(['from' => Document::STATUS_IN_REVIEW, 'to' => Document::STATUS_DRAFT, 'motif' => $reason])
            ->event('document_returned_to_draft')
            ->log('Renvoyé en brouillon');

        DocumentReviewNotifier::returnedToDraft($document, self::actor(), $reason);
    }

    public static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** Dépublication : l'historique de publication et de validation est conservé. */
    public static function archive(Document $document): void
    {
        abort_unless(auth()->user()?->can('document.publish'), 403);
        abort_unless($document->status === Document::STATUS_PUBLISHED, 403);

        $document->update(['status' => Document::STATUS_ARCHIVED]);
    }

    /** Outils de la fiche, en lecture seule. */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ActivitiesRelationManager::class,
            RelationManagers\LegacyRedirectsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
