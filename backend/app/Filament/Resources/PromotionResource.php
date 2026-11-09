<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\CampagneCandidatureResource\ExportsRetenusActions;
use App\Filament\Resources\CandidatureResource\DocumentsAuditeursActions;
use App\Filament\Resources\PromotionResource\Pages;
use App\Filament\Tables\TranslatedColumn;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Support\BelongsToModule;
use App\Support\CandidatureObjectStorage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Vie académique > Promotions (spec module 4, lot 2 §2) : réglages d'une
 * promotion (consignes, lettres, rentrée), sur le modèle de la campagne mais
 * sans aucun de ses champs. Ses propres droits : `scolarite.promotion` pour
 * modifier ; chaque action (documents, exports, matricules) garde la sienne.
 */
#[BelongsToModule('scolarite')]
class PromotionResource extends Resource
{
    use RestrictedToModule;

    public const PERMISSION = 'scolarite.promotion';

    protected static ?string $model = CampagneCandidature::class;

    /** Distinct de la future rubrique « Promotions » du CMS (module 6). */
    protected static ?string $slug = 'promotions-scolarite';

    /** Seul chemin que ce formulaire signe : un scan déposé par lui-même. */
    public const MOTIF_SIGNATURE = '#^signatures/signature-[0-9A-Za-z]{26}\.(png|jpe?g)$#';

    protected static ?string $modelLabel = 'Promotion';

    protected static ?string $pluralModelLabel = 'Promotions';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = GroupeNavigation::SCOLARITE;

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can(self::PERMISSION);
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
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

    /** Une promotion naît avec ses premiers retenus. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('candidatures', fn (Builder $q) => $q->where('statut', Candidature::STATUT_ACCEPTE))
            ->withCount([
                'candidatures as retenus_count' => fn (Builder $q) => $q->where('statut', Candidature::STATUT_ACCEPTE)->whereNull('withdrawn_at'),
                'candidatures as matricules_count' => fn (Builder $q) => $q->whereNotNull('matricule')->whereNull('withdrawn_at'),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make('Après la sélection')
                ->description('Affiché dans l\'espace privé des candidats retenus, sous l\'annonce de leur admission.')
                ->schema([
                    Forms\Components\Textarea::make('consignes_retenus')
                        ->label('Consignes aux candidats retenus')
                        ->rows(6)
                        ->maxLength(3000)
                        ->helperText('Inscription, pièces à apporter, date de rentrée… Texte simple, un paragraphe par ligne. Sans valeur, un message générique invite à attendre la convocation.'),
                ]),

            self::sectionDocumentsAuditeurs(),
        ]));
    }

    /**
     * Variables des lettres aux auditeurs (accès FOAD, admission). Laissé vide,
     * un champ reprend la valeur par défaut indiquée ; aucune date n'en a, et la
     * génération est refusée tant qu'il en manque une.
     */
    private static function sectionDocumentsAuditeurs(): Forms\Components\Section
    {
        $defaut = fn (string $cle): string => (string) config('documents_auditeurs.defauts.'.$cle);

        return Forms\Components\Section::make('Documents des auditeurs')
            ->description('Lettres d\'accès FOAD et lettres d\'admission, générées en Word depuis « Documents des auditeurs ». Un champ laissé vide reprend la valeur grisée. Enregistrez avant de générer : les lettres utilisent les valeurs enregistrées.')
            ->collapsible()
            ->schema([
                Forms\Components\TextInput::make('parametres_lettres.reference_acces')->label('Référence des lettres d\'accès FOAD')
                    ->placeholder($defaut('reference_acces'))->maxLength(120)->helperText('{annee} : année de la lettre ; {numero} : rang du matricule.'),
                Forms\Components\TextInput::make('parametres_lettres.reference_admission')->label('Référence des lettres d\'admission')
                    ->placeholder($defaut('reference_admission'))->maxLength(120),
                Forms\Components\DatePicker::make('parametres_lettres.date_lettres')->label('Date des lettres')->native(false)->displayFormat('d/m/Y'),
                Forms\Components\DatePicker::make('parametres_lettres.rentree_date')->label('Date de la rentrée solennelle')->native(false)->displayFormat('d/m/Y'),
                Forms\Components\DatePicker::make('parametres_lettres.rentree_date_fin')->label('Dernier jour de la rentrée (facultatif)')->native(false)->displayFormat('d/m/Y')
                    ->afterOrEqual('parametres_lettres.rentree_date')->helperText('Pour une rentrée sur plusieurs jours : « du 10 au 11 novembre 2026 ».'),
                Forms\Components\TextInput::make('parametres_lettres.rentree_heure')->label('Heure de la rentrée')->placeholder($defaut('rentree_heure'))->maxLength(40),
                Forms\Components\TextInput::make('parametres_lettres.rentree_lieu')->label('Lieu de la rentrée')->maxLength(250)
                    ->placeholder('à l\'Auditorium 300 de l\'immeuble principal du MINEPAT, boulevard du 20 mai')
                    ->helperText('Complète la phrase « aura lieu le … à 08:00 heures précises, … ».'),
                Forms\Components\Placeholder::make('frais_bareme')
                    ->label('Droits d\'inscription et tranches')
                    ->content('Montants et échéances viennent du barème M1 de l\'année (Vie académique > Barèmes) : la lettre et le déblocage du code d\'accès lisent la même valeur.')
                    ->columnSpanFull(),
                Forms\Components\TagsInput::make('parametres_lettres.copies')->label('Copies (lettres d\'admission)')
                    ->placeholder(implode(', ', (array) config('documents_auditeurs.defauts.copies'))),
                Forms\Components\FileUpload::make('signature_lettres_path')
                    ->label('Signature et cachet du Président du Comité de Pilotage (facultatif)')
                    ->disk(CandidatureObjectStorage::DISK)
                    // Stockage privé ; pas de HeadObject à l'ouverture (refusé par la clé MinIO de prod).
                    ->fetchFileInformation(false)
                    ->directory(DocumentsAuditeurs::DOSSIER_SIGNATURES)
                    ->visibility('private')
                    // Extension déduite du contenu, jamais du nom fourni par le navigateur.
                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => 'signature-'.Str::ulid().'.'.($file->guessExtension() ?: 'png'))
                    // L'état d'un champ de fichier se modifie depuis le navigateur : ne jamais
                    // signer un chemin qui n'est pas un scan de signature (bucket des pièces).
                    ->getUploadedFileUsing(fn (string $file): ?array => preg_match(self::MOTIF_SIGNATURE, $file) === 1 ? [
                        'name' => basename($file), 'size' => 0, 'type' => null,
                        'url' => Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl($file, now()->addMinutes(5)),
                    ] : null)
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])
                    ->maxSize(2048)
                    ->helperText('Sans scan déposé, les lettres sortent sans signature, pour être signées à la main. PNG à fond transparent de préférence, 2 Mo maximum.')
                    ->columnSpanFull(),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('promotion_numero', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('promotion_numero')->label('Promotion')->formatStateUsing(fn ($state): string => $state.'e promotion'),
                TranslatedColumn::make('nom')->label('Campagne')->limit(45),
                Tables\Columns\TextColumn::make('annee')->label('Année')->getStateUsing(fn (CampagneCandidature $record): string => $record->anneeAcademique()),
                Tables\Columns\TextColumn::make('retenus_count')->label('Retenus'),
                Tables\Columns\TextColumn::make('matricules_count')->label('Matricules'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Réglages'),
                Tables\Actions\ActionGroup::make([
                    ExportsRetenusActions::attribuer(Tables\Actions\Action::make('attribuerMatricules')),
                    ExportsRetenusActions::fullData(Tables\Actions\Action::make('exportFullData')),
                    ExportsRetenusActions::acma(Tables\Actions\Action::make('exportAcma')),
                    ExportsRetenusActions::moodle(Tables\Actions\Action::make('exportMoodle')),
                ])->label('Matricules et exports')->icon('heroicon-o-identification')->button()->color('gray'),
                Tables\Actions\ActionGroup::make([
                    DocumentsAuditeursActions::campagne(Tables\Actions\Action::make('accesFoad'), ParametresLettres::DOCUMENT_ACCES),
                    DocumentsAuditeursActions::campagne(Tables\Actions\Action::make('lettresAdmission'), ParametresLettres::DOCUMENT_ADMISSION),
                ])->label('Documents des auditeurs')->icon('heroicon-o-document-text')->button()->color('gray'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotions::route('/'),
            'edit' => Pages\EditPromotion::route('/{record}/edit'),
        ];
    }
}
