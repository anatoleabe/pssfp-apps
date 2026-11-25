<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\DocumentDelivreResource\Pages;
use App\Models\DocumentDelivre;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registre des documents délivrés (spec lot 21) : relevés, attestations, certificats, autorisations
 * de dépôt et PV, en lecture seule. Le fichier servi est toujours celui qui a été scellé.
 */
#[BelongsToModule('lmd')]
class DocumentDelivreResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = DocumentDelivre::class;

    protected static ?string $slug = 'documents-delivres';

    protected static ?string $modelLabel = 'Document délivré';

    protected static ?string $pluralModelLabel = 'Registre des documents';

    /** Libellé tel quel dans le menu : « Prises en charge », pas « Prises En Charge » (lot 33). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = GroupeNavigation::EVALUATIONS;

    protected static ?int $navigationSort = 60;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(PermissionsLmd::CONSULTER);
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

    /** Le fichier d'un document porte des notes ou une décision : réservé à qui peut le révoquer. */
    public static function peutTelecharger(): bool
    {
        return (bool) auth()->user()?->can(PermissionsLmd::DOCUMENTS_REVOQUER);
    }

    public static function getEloquentQuery(): Builder
    {
        return DocumentDelivre::query()->with(['delivrePar:id,name', 'deliberation:id,numero_pv']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('type')->label('Document')
                    ->formatStateUsing(fn (string $state): string => DocumentDelivre::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('titulaire')->label('Titulaire')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('created_at')->label('Délivré le')->dateTime('d/m/Y H:i', 'Africa/Douala')->sortable(),
                Tables\Columns\TextColumn::make('delivrePar.name')->label('Par')->placeholder('L\'auditeur'),
                Tables\Columns\TextColumn::make('deliberation.numero_pv')->label('Délibération')->fontFamily('mono')->placeholder('—'),
                Tables\Columns\TextColumn::make('code_verification')->label('Code')->fontFamily('mono')->copyable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('revoque_at')->label('Statut')->badge()
                    ->getStateUsing(fn (DocumentDelivre $record): string => $record->estRevoque() ? 'Révoqué' : 'Valide')
                    ->color(fn (string $state): string => $state === 'Valide' ? 'success' : 'danger')
                    ->description(fn (DocumentDelivre $record): ?string => $record->estRevoque()
                        ? 'le '.$record->revoque_at->timezone('Africa/Douala')->format('d/m/Y').' : '.$record->revoque_motif : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Document')->native(false)->options(DocumentDelivre::TYPES),
                Tables\Filters\TernaryFilter::make('revoque')->label('Statut')->placeholder('Tous')->trueLabel('Révoqués')->falseLabel('Valides')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('revoque_at'),
                        false: fn (Builder $q): Builder => $q->whereNull('revoque_at'),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('telecharger')->label('PDF')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => self::peutTelecharger())
                    ->action(function (DocumentDelivre $record): ?StreamedResponse {
                        abort_unless(self::peutTelecharger(), 403);
                        try {
                            $pdf = app(DocumentsOfficiels::class)->contenu($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Document indisponible')->body($e->getMessage())->send();

                            return null;
                        }
                        activity('lmd')->causedBy(auth()->user())->performedOn($record)->event('document_consulte')
                            ->withProperties(['numero' => $record->numero])->log('Document officiel téléchargé depuis le registre');

                        return response()->streamDownload(fn () => print ($pdf), mb_strtolower($record->numero).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                Tables\Actions\Action::make('revoquer')->label('Révoquer')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (DocumentDelivre $record): bool => ! $record->estRevoque() && $record->type !== DocumentDelivre::PV
                        && (bool) auth()->user()?->can(PermissionsLmd::DOCUMENTS_REVOQUER))
                    ->modalDescription('Le document reste au registre, marqué « révoqué » à la vérification. L\'auditeur pourra en obtenir un nouveau.')
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500),
                    ]))
                    ->action(function (DocumentDelivre $record, array $data): void {
                        try {
                            app(DocumentsOfficiels::class)->revoquer($record, (string) $data['motif'], auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Révocation refusée')->body($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title('Document '.$record->numero.' révoqué')->send();
                    }),
            ])
            ->recordUrl(null)
            ->paginated([50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocumentsDelivres::route('/')];
    }
}
