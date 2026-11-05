<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\CampagneCandidatureResource\ExportsRetenusActions;
use App\Filament\Resources\CampagneCandidatureResource\Pages;
use App\Filament\Resources\CampagneCandidatureResource\ResultatsSelectionActions;
use App\Filament\Resources\CandidatureResource\DocumentsAuditeursActions;
use App\Filament\Tables\TranslatedColumn;
use App\Models\CampagneCandidature;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Support\BelongsToModule;
use App\Support\CandidatureObjectStorage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Resource Filament minimaliste pour gérer les campagnes (PR D arbitrage A1).
 *
 * Permet à super_admin / admin d'ouvrir P15/2027 le moment venu sans passer
 * par tinker. Pas de delete (FK RESTRICT).
 */
#[BelongsToModule('admissions')]
class CampagneCandidatureResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = CampagneCandidature::class;

    protected static ?string $modelLabel = 'Campagne';

    protected static ?string $pluralModelLabel = 'Campagnes';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Module 5 — Admissions';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'nom';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Campagne')->schema([
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(50),
                Forms\Components\TextInput::make('nom.fr')
                    ->label('Nom de la campagne (FR)')
                    ->required()
                    ->maxLength(100),
                Forms\Components\TextInput::make('nom.en')
                    ->label('Nom de la campagne (EN)')
                    ->maxLength(100)
                    ->helperText('Affiché en titre sur la version anglaise du portail. Sans valeur, le français est servi.'),
                Forms\Components\TextInput::make('promotion_numero')->required()->numeric()->minValue(1),
                Forms\Components\TextInput::make('prefix_numero')->required()->maxLength(20)
                    ->helperText('Ex : P14026- pour Promotion 14, année 2026.'),
                Forms\Components\Select::make('status')->required()->options([
                    'draft' => 'Brouillon',
                    'open' => 'Ouverte',
                    'closed' => 'Clôturée',
                    'archived' => 'Archivée',
                ]),
                Forms\Components\TextInput::make('max_voeux')->numeric()->default(1)->minValue(1)->maxValue(5),
                Forms\Components\DateTimePicker::make('opens_at')->required()->native(false),
                Forms\Components\DateTimePicker::make('closes_at')->required()->native(false)->after('opens_at'),
                Forms\Components\DateTimePicker::make('results_at')->native(false)->after('closes_at'),
            ])->columns(2),

            Forms\Components\Section::make('Communiqué des résultats')
                ->description('Publié sur la page d\'accueil de apply.pssfp.org et dans l\'espace des candidats dès qu\'un PDF est déposé ici, même campagne close. Il ne remplace pas le communiqué de l\'appel ci-dessous.')
                ->schema([
                    Forms\Components\FileUpload::make('communique_resultats_path')
                        ->label('PDF du communiqué des résultats signé')
                        ->disk(CampagneCandidature::COMMUNIQUE_DISK)
                        // Pas de HeadObject à l'ouverture du formulaire : la clé MinIO de prod
                        // le refuse et Filament retirerait alors le PDF déjà déposé.
                        ->fetchFileInformation(false)
                        ->directory('communiques')
                        ->visibility('public')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(10240)
                        ->downloadable()
                        ->openable()
                        ->previewable(false)
                        ->helperText('PDF uniquement, 10 Mo maximum.')
                        ->getUploadedFileNameForStorageUsing(
                            fn (Forms\Get $get): string => 'resultats-'
                                .Str::slug((string) ($get('slug') ?: 'campagne')).'.pdf',
                        ),
                ]),

            Forms\Components\Section::make('Communiqué conjoint de l\'appel à candidature')
                ->description('Publié en téléchargement sur la page d\'accueil de apply.pssfp.org tant que la campagne est ouverte. Pour les résultats, utilisez la section ci-dessus.')
                ->schema([
                    Forms\Components\FileUpload::make('communique_pdf_path')
                        ->label('PDF du communiqué signé — version française')
                        ->disk(CampagneCandidature::COMMUNIQUE_DISK)
                        // Pas de HeadObject à l'ouverture du formulaire : la clé MinIO de prod
                        // le refuse et Filament retirerait alors le PDF déjà déposé.
                        ->fetchFileInformation(false)
                        ->directory('communiques')
                        ->visibility('public')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(10240)
                        ->downloadable()
                        ->openable()
                        ->previewable(false)
                        ->helperText('PDF uniquement, 10 Mo maximum. Le scan signé MINFI + Recteur UY2-Soa.')
                        ->getUploadedFileNameForStorageUsing(
                            fn (Forms\Get $get): string => 'appel-candidature-'
                                .Str::slug((string) ($get('slug') ?: 'campagne')).'-fr.pdf',
                        ),
                    Forms\Components\FileUpload::make('communique_pdf_path_en')
                        ->label('PDF du communiqué signé — version anglaise')
                        ->disk(CampagneCandidature::COMMUNIQUE_DISK)
                        // Pas de HeadObject à l'ouverture du formulaire : la clé MinIO de prod
                        // le refuse et Filament retirerait alors le PDF déjà déposé.
                        ->fetchFileInformation(false)
                        ->directory('communiques')
                        ->visibility('public')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(10240)
                        ->downloadable()
                        ->openable()
                        ->previewable(false)
                        ->helperText('Facultatif. Sans ce fichier, la version anglaise du site propose le PDF français en le signalant clairement.')
                        ->getUploadedFileNameForStorageUsing(
                            fn (Forms\Get $get): string => 'appel-candidature-'
                                .Str::slug((string) ($get('slug') ?: 'campagne')).'-en.pdf',
                        ),
                    Forms\Components\TextInput::make('communique_reference')
                        ->label('Référence du communiqué')
                        ->maxLength(100)
                        ->placeholder('N° 90001121/C/MINFI/SG/PSSFP/PCP/PCS/UPAAS/AT'),
                    Forms\Components\DatePicker::make('communique_signed_at')
                        ->label('Date de signature')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                ])->columns(2),

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
        ]);
    }

    /**
     * Variables des lettres aux auditeurs (accès FOAD, admission). Laissé vide,
     * un champ reprend la valeur par défaut indiquée ; aucune date n'en a, et la
     * génération est refusée tant qu'il en manque une.
     */
    private static function sectionDocumentsAuditeurs(): Forms\Components\Section
    {
        $defaut = fn (string $cle): string => (string) config('documents_auditeurs.defauts.'.$cle);
        $montant = fn (string $cle, string $libelle): Forms\Components\TextInput => Forms\Components\TextInput::make('parametres_lettres.'.$cle)
            ->label($libelle)->numeric()->minValue(0)->suffix('FCFA')->placeholder(number_format((int) $defaut($cle), 0, ',', ' '));

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
                $montant('frais_inscription_montant', 'Frais d\'inscription et péri-académiques'),
                Forms\Components\TextInput::make('parametres_lettres.frais_inscription_echeance')->label('Échéance des frais d\'inscription')
                    ->placeholder($defaut('frais_inscription_echeance'))->maxLength(120),
                $montant('frais_tranche1_montant', '1re tranche'),
                Forms\Components\DatePicker::make('parametres_lettres.frais_tranche1_date')->label('Échéance de la 1re tranche')->native(false)->displayFormat('d/m/Y'),
                $montant('frais_tranche2_montant', '2e tranche'),
                Forms\Components\DatePicker::make('parametres_lettres.frais_tranche2_date')->label('Échéance de la 2e tranche')->native(false)->displayFormat('d/m/Y'),
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
            ->defaultSort('opens_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable(),
                TranslatedColumn::make('nom')->label('Nom (FR)')->searchable()->limit(45),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'draft' => 'gray', 'open' => 'success', 'closed' => 'warning', 'archived' => 'danger',
                    default => 'gray',
                }),
                Tables\Columns\TextColumn::make('opens_at')->dateTime('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('closes_at')->dateTime('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('candidatures_count')
                    ->label('Dossiers')
                    ->counts('candidatures')
                    ->sortable(),
                Tables\Columns\IconColumn::make('communique_pdf_path')
                    ->label('Communiqué')
                    ->boolean()
                    ->tooltip(fn (CampagneCandidature $record): string => $record->communique_pdf_path === null
                        ? 'Aucun communiqué publié — la page d\'accueil n\'affiche pas de bouton de téléchargement.'
                        : 'Communiqué publié sur apply.pssfp.org'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'draft' => 'Brouillon', 'open' => 'Ouverte',
                    'closed' => 'Clôturée', 'archived' => 'Archivée',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    ResultatsSelectionActions::communique(Tables\Actions\Action::make('communiqueResultats')),
                    ResultatsSelectionActions::pv(Tables\Actions\Action::make('pvJury')),
                    ResultatsSelectionActions::liste(Tables\Actions\Action::make('listeRetenus')),
                ])
                    ->label('Résultats de sélection')
                    ->icon('heroicon-o-trophy')
                    ->button()
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    ExportsRetenusActions::attribuer(Tables\Actions\Action::make('attribuerMatricules')),
                    ExportsRetenusActions::fullData(Tables\Actions\Action::make('exportFullData')),
                    ExportsRetenusActions::acma(Tables\Actions\Action::make('exportAcma')),
                    ExportsRetenusActions::moodle(Tables\Actions\Action::make('exportMoodle')),
                ])
                    ->label('Retenus : matricules et exports')
                    ->icon('heroicon-o-identification')
                    ->button()
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    DocumentsAuditeursActions::campagne(Tables\Actions\Action::make('accesFoad'), ParametresLettres::DOCUMENT_ACCES),
                    DocumentsAuditeursActions::campagne(Tables\Actions\Action::make('lettresAdmission'), ParametresLettres::DOCUMENT_ADMISSION),
                ])
                    ->label('Documents des auditeurs')
                    ->icon('heroicon-o-document-text')
                    ->button()
                    ->color('gray'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampagneCandidatures::route('/'),
            'create' => Pages\CreateCampagneCandidature::route('/create'),
            'edit' => Pages\EditCampagneCandidature::route('/{record}/edit'),
        ];
    }
}
