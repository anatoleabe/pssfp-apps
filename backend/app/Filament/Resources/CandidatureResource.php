<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Events\CandidatureAccepted;
use App\Events\CandidatureRefused;
use App\Filament\Resources\CandidatureResource\Pages;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DepartementCameroun;
use App\Models\Pays;
use App\Models\RegionCameroun;
use App\Services\CandidatureService;
use App\Services\DepotPhysiqueService;
use App\Services\DocumentUploadService;
use App\Services\RecipisseService;
use App\Services\TestCandidaturePurgeService;
use App\Support\CandidatureDocumentTypeLabel;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Resource Filament Candidature — coeur du module 5 admin (PR D).
 *
 * Particularités :
 * - Pas de création via panel (canCreate = false, cf. arbitrage D PR D).
 * - Édition lecture seule pour les dossiers décidés sauf super_admin (P-min-1).
 * - Champs systèmes (uuid, numero_dossier, phone_e164, user_id, statut, dates de
 *   transition, recipisse_*) sont disabled() dans le form pour empêcher leur
 *   modification via Filament (ajout 5 PR D).
 * - Filtres persistés en session pour confort UX du comité.
 */
class CandidatureResource extends Resource
{
    protected static ?string $model = Candidature::class;

    protected static ?string $modelLabel = 'Candidature';

    protected static ?string $pluralModelLabel = 'Candidatures';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Module 5 — Admissions';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'numero_dossier';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identité technique (lecture seule)')
                ->columns(3)
                ->collapsed()
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('numero_dossier')->disabled(),
                    Forms\Components\TextInput::make('uuid')->disabled(),
                    Forms\Components\TextInput::make('statut')->disabled(),
                    Forms\Components\TextInput::make('phone_e164')->label('Téléphone (login)')->disabled(),
                    Forms\Components\TextInput::make('email')->disabled(),
                    Forms\Components\Select::make('campagne_id')
                        ->relationship('campagne', 'nom')
                        ->disabled(),
                ]),

            Forms\Components\Section::make('Identité civile')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('civilite')->options([
                        'M.' => 'M.', 'Mme' => 'Mme',
                    ]),
                    Forms\Components\TextInput::make('nom')->maxLength(100),
                    Forms\Components\TextInput::make('prenom')->maxLength(100),
                    Forms\Components\TextInput::make('epouse')->label('Nom de naissance')->maxLength(100),
                    Forms\Components\DatePicker::make('date_naissance')->native(false),
                    Forms\Components\TextInput::make('lieu_naissance')->maxLength(100),
                    Forms\Components\Select::make('genre')->options([
                        'M' => 'Masculin', 'F' => 'Féminin', 'autre' => 'Autre',
                    ]),
                    Forms\Components\TextInput::make('statut_matrimonial')->maxLength(20),
                    Forms\Components\Select::make('nationalite')
                        ->relationship('paysNationalite', 'nom')
                        ->searchable()
                        ->preload(),
                ]),

            Forms\Components\Section::make('Géographie')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('pays_origine')
                        ->options(fn () => Pays::orderBy('nom')->pluck('nom', 'code_iso'))
                        ->searchable(),
                    Forms\Components\Select::make('pays_residence')
                        ->options(fn () => Pays::orderBy('nom')->pluck('nom', 'code_iso'))
                        ->searchable()
                        ->live(),
                    Forms\Components\Select::make('region')
                        ->options(fn () => RegionCameroun::orderBy('order')->pluck('nom', 'code'))
                        ->searchable()
                        ->visible(fn (Forms\Get $get) => $get('pays_residence') === 'CM'),
                    Forms\Components\Select::make('departement')
                        ->options(function (Forms\Get $get) {
                            $region = $get('region');
                            if (! $region) {
                                return [];
                            }

                            return DepartementCameroun::where('region_code', $region)
                                ->orderBy('nom')->pluck('nom', 'code');
                        })
                        ->searchable()
                        ->visible(fn (Forms\Get $get) => $get('pays_residence') === 'CM'),
                    Forms\Components\TextInput::make('adresse')->maxLength(200)->columnSpanFull(),
                    Forms\Components\TextInput::make('ville_residence')->maxLength(100),
                ]),

            Forms\Components\Section::make('Contact secondaire')
                ->columns(2)
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('indicatif1'),
                    Forms\Components\TextInput::make('telephone1'),
                    Forms\Components\TextInput::make('indicatif2'),
                    Forms\Components\TextInput::make('telephone2'),
                ]),

            Forms\Components\Section::make('Choix pédagogique')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('specialite')
                        ->options(fn () => collect((array) config('specialites'))->mapWithKeys(
                            fn ($label) => [$label => $label]
                        )->all())
                        ->searchable(),
                    Forms\Components\TextInput::make('second_choix')->maxLength(100),
                    Forms\Components\Select::make('type_etude')->options([
                        'presentiel' => 'Présentiel', 'distanciel' => 'Distanciel',
                    ]),
                    Forms\Components\Select::make('premiere_langue')->options([
                        'fr' => 'Français', 'en' => 'Anglais',
                    ]),
                    Forms\Components\TextInput::make('diplome_obtenu'),
                    Forms\Components\TextInput::make('institut'),
                    Forms\Components\TextInput::make('specialite_diplome'),
                    Forms\Components\TextInput::make('annee_diplome')->numeric()->minValue(1950)->maxValue(now()->year),
                    Forms\Components\Select::make('diplome_requis')
                        ->label('Diplôme requis')
                        ->options(config('diplome_requis'))
                        ->native(false),
                    Forms\Components\TextInput::make('annee_diplome_requis')
                        ->label("Année d'obtention du diplôme requis")
                        ->numeric()->minValue(1950)->maxValue(now()->year),
                    Forms\Components\Select::make('domaine_diplome_requis')
                        ->label('Domaine du diplôme requis')
                        ->options(config('domaines_diplome'))
                        ->native(false)
                        ->live(),
                    Forms\Components\TextInput::make('specialite_diplome_requis')
                        ->label('Spécialité du diplôme requis')
                        ->maxLength(100)
                        ->visible(fn (Forms\Get $get): bool => $get('domaine_diplome_requis') === 'autres'),
                    Forms\Components\TextInput::make('institut_diplome_requis')
                        ->label('Établissement de délivrance du diplôme requis')
                        ->maxLength(150),
                    Forms\Components\Repeater::make('autres_diplomes')
                        ->label('Autres diplômes académiques')
                        ->schema([
                            Forms\Components\TextInput::make('intitule')->label('Intitulé du diplôme')->maxLength(150),
                            Forms\Components\TextInput::make('etablissement')->label("Établissement d'obtention")->maxLength(150),
                            Forms\Components\TextInput::make('annee')->label("Année d'obtention")->numeric()->minValue(1950)->maxValue(now()->year),
                        ])
                        ->columns(3)
                        ->maxItems(10)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                    Forms\Components\Repeater::make('formations_professionnelles')
                        ->label('Formations professionnelles')
                        ->schema([
                            Forms\Components\TextInput::make('centre')->label('Centre de formation')->maxLength(150),
                            Forms\Components\TextInput::make('qualification')->label('Qualification obtenue')->maxLength(150),
                            Forms\Components\TextInput::make('annee')->label('Année de formation')->numeric()->minValue(1950)->maxValue(now()->year),
                        ])
                        ->columns(3)
                        ->maxItems(10)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('statut_actuel')->options([
                        'Etudiant' => 'Étudiant(e)',
                        'Sans-emploi' => 'Sans emploi / en recherche d’emploi',
                        'Fonctionnaire' => 'Fonctionnaire titulaire',
                        'Contractuel-Etat' => 'Agent contractuel de l’État',
                        'Etablissement-public' => 'Agent d’un établissement public',
                        'Entreprise-publique' => 'Salarié(e) d’une entreprise publique',
                        'Prive' => 'Salarié(e) du secteur privé',
                        'Independant' => 'Indépendant(e) / profession libérale',
                        'ONG-International' => 'ONG / organisation internationale',
                        'Autre' => 'Autre situation professionnelle',
                        'Fonctionnaire-Contractuel' => 'Fonctionnaire / Contractuel (ancien dossier)',
                    ]),
                    Forms\Components\TextInput::make('fonction_actuelle')->label('Fonction ou poste'),
                    Forms\Components\TextInput::make('employeur'),
                    Forms\Components\TextInput::make('adresse_employeur'),
                    Forms\Components\TextInput::make('tel_employeur'),
                ]),

            Forms\Components\Section::make('Engagement & marketing')
                ->columns(2)
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('engagement_nom')->maxLength(200),
                    Forms\Components\TextInput::make('moyen_connaissance'),
                    Forms\Components\TextInput::make('moyen_connaissance_detail')->label('Précision sur la source'),
                ]),

            Forms\Components\Section::make('Frais & paiement')
                ->columns(3)
                ->collapsible()
                ->schema([
                    Forms\Components\Toggle::make('frais_paye')->disabled()
                        ->helperText('Modifiable uniquement via l\'action « Marquer frais payés ».'),
                    Forms\Components\TextInput::make('mode_paiement')->disabled(),
                    Forms\Components\TextInput::make('reference_paiement')->disabled(),
                    Forms\Components\DatePicker::make('date_paiement')->disabled()->native(false),
                ]),

            Forms\Components\Section::make('Tracking dossier')
                ->columns(4)
                ->collapsed()
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('submitted_at')->disabled(),
                    Forms\Components\TextInput::make('reviewed_at')->disabled(),
                    Forms\Components\TextInput::make('decided_at')->disabled(),
                    Forms\Components\TextInput::make('withdrawn_at')->disabled(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSearchInSession()
            // Derniers déposants en tête. Le tri se fait sur la colonne
            // `submitted_at` dont le `sortable(query:)` impose NULLS LAST :
            // sans lui PostgreSQL remonte les NULL en premier en DESC, et la
            // liste s'ouvrait sur les dossiers jamais soumis.
            ->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('campagne')
                ->withCount('documents'))
            ->columns([
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Déposé le')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw('submitted_at '.(strtolower($direction) === 'asc' ? 'asc' : 'desc').' nulls last'))
                    ->placeholder('Non soumis')
                    ->formatStateUsing(fn (?Candidature $record): string => $record?->submitted_at === null
                        ? 'Non soumis'
                        : $record->submitted_at->translatedFormat('d/m/Y').' à '.$record->submitted_at->format('H\hi'))
                    ->description(fn (Candidature $record): ?string => $record->submitted_at?->diffForHumans())
                    ->weight(FontWeight::Medium)
                    ->color(fn (Candidature $record): ?string => $record->submitted_at === null ? 'gray' : null),
                Tables\Columns\TextColumn::make('numero_dossier')
                    ->label('N° dossier')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Numéro de dossier copié')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('nom_complet')
                    ->label('Candidat')
                    ->wrap()
                    ->state(fn (Candidature $r): string => trim("{$r->prenom} {$r->nom}"))
                    // Téléphone et email en sous-ligne : deux colonnes de moins
                    // à balayer, et la recherche continue de porter dessus.
                    ->description(fn (Candidature $r): string => trim($r->phone_e164.($r->email ? ' · '.$r->email : '')))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(fn (Builder $q): Builder => $q
                            ->where('nom', 'ilike', "%{$search}%")
                            ->orWhere('prenom', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                            ->orWhere('phone_e164', 'ilike', "%{$search}%"));
                    }),
                Tables\Columns\TextColumn::make('statut')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state) => match ($state) {
                        'postulant' => 'warning',
                        'candidat' => 'info',
                        'accepte' => 'success',
                        'refuse' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('frais_paye')->label('Frais')
                    ->boolean()->trueIcon('heroicon-o-check-circle')->falseIcon('heroicon-o-x-circle')
                    ->tooltip(fn (Candidature $r): string => $r->frais_paye ? 'Frais payés' : 'Frais non payés'),
                Tables\Columns\TextColumn::make('documents_count')
                    ->label('Pièces')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->formatStateUsing(fn (int $state): string => (string) $state),
                Tables\Columns\IconColumn::make('recipisse_pdf_path')
                    ->label('Récépissé')
                    ->boolean()
                    ->tooltip(fn (Candidature $r): string => $r->recipisse_pdf_path === null
                        ? 'Aucun récépissé généré'
                        : 'Récépissé disponible au téléchargement'),
                Tables\Columns\TextColumn::make('depot_physique_at')
                    ->label('Dossier papier')
                    ->badge()
                    ->sortable()
                    ->placeholder('Non reçu')
                    ->color(fn (?string $state): string => $state === null ? 'gray' : 'success')
                    ->formatStateUsing(fn (?string $state, Candidature $r): string => $state === null
                        ? 'Non reçu'
                        : 'Reçu le '.$r->depot_physique_at->translatedFormat('d/m/Y'))
                    ->description(fn (Candidature $r): ?string => $r->depot_physique_at === null
                        ? null
                        : $r->depotPhysiquePar?->name),
                Tables\Columns\TextColumn::make('campagne.nom')->label('Année académique')->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('specialite')->limit(28)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('region')->label('Région')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('decided_at')->label('Décidé le')
                    ->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('campagne_id')
                    ->label('Campagne')
                    ->options(fn () => CampagneCandidature::orderByDesc('opens_at')->pluck('nom', 'id'))
                    ->default(fn () => CampagneCandidature::currentlyOpen()->value('id')),
                Tables\Filters\SelectFilter::make('statut')->multiple()->options([
                    'postulant' => 'Postulant',
                    'candidat' => 'Candidat',
                    'accepte' => 'Accepté',
                    'refuse' => 'Refusé',
                ]),
                Tables\Filters\SelectFilter::make('region')
                    ->options(fn () => RegionCameroun::orderBy('order')->pluck('nom', 'code')),
                Tables\Filters\TernaryFilter::make('frais_paye')
                    ->label('Frais payés')
                    ->placeholder('Tous')
                    ->trueLabel('Payés uniquement')
                    ->falseLabel('Non payés uniquement'),
                // Liste nominative des brouillons à relancer, avec téléphone
                // et email déjà présents dans la colonne « Candidat ». Les
                // identifiants sont calculés en PHP par CandidatureService,
                // faute de pouvoir exprimer en SQL les règles conditionnelles
                // de checkSubmittable sans les dupliquer.
                Tables\Filters\SelectFilter::make('blocage_brouillon')
                    ->label('Brouillons à relancer')
                    ->options([
                        CandidatureService::DRAFT_READY => 'Prêts à soumettre',
                        CandidatureService::DRAFT_PHOTO_ONLY => 'Bloqués par la photo',
                        CandidatureService::DRAFT_OTHER => 'Autres champs manquants',
                    ])
                    ->query(function (Builder $query, array $data, HasTable $livewire): Builder {
                        $cause = $data['value'] ?? null;
                        if ($cause === null || $cause === '') {
                            return $query;
                        }

                        // La campagne est lue dans l'état du filtre voisin, et
                        // non dans la requête HTTP : un admin qui consulte une
                        // campagne passée doit obtenir SES brouillons, pas ceux
                        // de la campagne ouverte.
                        $campagneId = $livewire->getTableFilterState('campagne_id')['value'] ?? null;
                        $campagneId = $campagneId ?: CampagneCandidature::currentlyOpen()->value('id');

                        if ($campagneId === null) {
                            return $query->whereRaw('1 = 0');
                        }

                        $buckets = app(CandidatureService::class)
                            ->classifyDraftsForCampagne((int) $campagneId);

                        return $query->whereIn('id', $buckets[$cause] ?? []);
                    }),
                Tables\Filters\TernaryFilter::make('depot_physique')
                    ->label('Dossier papier')
                    ->placeholder('Tous')
                    ->trueLabel('Reçus au guichet')
                    ->falseLabel('En attente de dépôt')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->deposePhysiquement(),
                        false: fn (Builder $query): Builder => $query->enAttenteDepotPhysique(),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->iconButton()
                    ->tooltip('Consulter le dossier'),
                Tables\Actions\Action::make('viewRecipisse')
                    ->label('Récépissé PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->iconButton()
                    ->tooltip('Télécharger le récépissé PDF')
                    ->visible(fn (Candidature $r) => $r->recipisse_pdf_path !== null)
                    ->action(function (Candidature $r): void {
                        // Ajout 1 PR D : trace l'accès admin au récépissé.
                        activity('candidatures')
                            ->causedBy(auth()->user())
                            ->performedOn($r)
                            ->withProperties(['type' => 'admin_download'])
                            ->event('recipisse_downloaded_by_admin')
                            ->log('Récépissé téléchargé par un admin');

                        $url = app(RecipisseService::class)->signedUrl($r->recipisse_pdf_path);
                        redirect($url);
                    }),
                Tables\Actions\Action::make('viewDocuments')
                    ->label('Pièces justificatives')
                    ->icon('heroicon-o-paper-clip')
                    ->iconButton()
                    ->tooltip(fn (Candidature $r): string => 'Pièces justificatives ('.($r->documents_count ?? $r->documents()->count()).')')
                    ->visible(fn (Candidature $r): bool => ($r->documents_count ?? $r->documents()->count()) > 0)
                    ->modalHeading('Pièces justificatives')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalContent(function (Candidature $r): View {
                        $service = app(DocumentUploadService::class);

                        return view('filament.candidature.documents-modal', [
                            'documents' => $r->documents()->latest()->get()->map(fn ($d) => [
                                'label' => CandidatureDocumentTypeLabel::for($d->type),
                                'filename' => $d->original_filename,
                                'uploaded_at' => $d->created_at,
                                'url' => $service->signedUrl($d),
                            ]),
                        ]);
                    }),
                Tables\Actions\Action::make('markDepotPhysique')
                    ->label('Dossier reçu')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('success')
                    ->iconPosition(IconPosition::Before)
                    ->visible(fn (Candidature $r): bool => auth()->user()?->can('candidature.mark_depot_physique') === true
                        && $r->submitted_at !== null
                        && $r->depot_physique_at === null)
                    ->modalHeading('Réception du dossier papier')
                    ->modalDescription('À cocher une fois le dossier physique remis au bureau de la scolarité (Yaoundé-Messa, porte 231).')
                    ->modalSubmitActionLabel('Enregistrer la réception')
                    ->form([
                        Forms\Components\DatePicker::make('recu_le')
                            ->label('Date de réception effective')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->maxDate(now())
                            ->default(now())
                            ->helperText('Modifiable : saisissez la date à laquelle le dossier a réellement été déposé au guichet.'),
                        Forms\Components\TextInput::make('observation')
                            ->label('Observation (facultatif)')
                            ->maxLength(255)
                            ->placeholder('Ex : enveloppe A4 non timbrée, relevé L2 manquant.'),
                    ])
                    ->action(function (Candidature $r, array $data): void {
                        app(DepotPhysiqueService::class)->marquer(
                            candidature: $r,
                            recuLe: Carbon::parse($data['recu_le']),
                            agent: auth()->user(),
                            observation: $data['observation'] ?? null,
                        );

                        Notification::make()
                            ->title('Réception enregistrée')
                            ->body("Dossier {$r->numero_dossier} marqué comme déposé physiquement.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->label('Modifier le dossier')
                        ->icon('heroicon-o-pencil-square'),
                    Tables\Actions\Action::make('regenerateRecipisse')
                        ->label('Régénérer le récépissé')
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->visible(fn (Candidature $r): bool => auth()->user()?->can('candidature.regenerate_recipisse') === true
                            && $r->submitted_at !== null)
                        ->requiresConfirmation()
                        ->modalHeading('Régénérer le récépissé PDF')
                        ->modalDescription('Le récépissé est refabriqué à partir des données actuelles du dossier (photo comprise) et remplace le fichier existant. Le statut, les dates et le numéro de dossier ne changent pas.')
                        ->modalSubmitActionLabel('Régénérer')
                        ->action(function (Candidature $r): void {
                            static::regenerateRecipisse($r);

                            Notification::make()
                                ->title('Récépissé régénéré')
                                ->body("Le récépissé du dossier {$r->numero_dossier} a été refabriqué.")
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\Action::make('annulerDepotPhysique')
                        ->label('Annuler la réception')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('gray')
                        ->visible(fn (Candidature $r): bool => auth()->user()?->can('candidature.mark_depot_physique') === true
                            && $r->depot_physique_at !== null)
                        ->modalHeading('Annuler la réception du dossier papier')
                        ->form([
                            Forms\Components\TextInput::make('motif')
                                ->label('Motif de la correction')
                                ->required()
                                ->minLength(5)
                                ->maxLength(255),
                        ])
                        ->action(function (Candidature $r, array $data): void {
                            app(DepotPhysiqueService::class)->annuler($r, auth()->user(), $data['motif']);
                        }),
                    Tables\Actions\Action::make('deleteTestAccount')
                        ->label('Supprimer le compte de test')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (Candidature $r): bool => auth()->user()?->can('candidature.delete_test')
                            && $r->statut === Candidature::STATUT_POSTULANT
                            && $r->submitted_at === null)
                        ->modalHeading('Suppression définitive du compte de recette')
                        ->modalDescription('Le dossier sera soft-deleted ; sa photo et ses pièces seront purgées de MinIO. Cette action est réservée aux données de test.')
                        ->form([
                            Forms\Components\TextInput::make('numero_confirmation')
                                ->label('Saisissez le numéro de dossier pour confirmer')
                                ->required(),
                        ])
                        ->action(function (Candidature $r, array $data): void {
                            if (($data['numero_confirmation'] ?? '') !== $r->numero_dossier) {
                                throw ValidationException::withMessages([
                                    'numero_confirmation' => 'Le numéro de dossier ne correspond pas.',
                                ]);
                            }
                            app(TestCandidaturePurgeService::class)->purge($r);
                        }),
                    Tables\Actions\Action::make('markAsCandidat')
                        ->label('Marquer comme candidat')
                        ->icon('heroicon-o-arrow-right-circle')
                        ->color('info')
                        ->visible(fn () => auth()->user()?->can('candidature.update_status'))
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('motif')
                                ->label('Motif de rétrogradation/promotion')
                                ->required()
                                ->minLength(10)
                                ->helperText('Obligatoire — sera tracé dans le journal d\'activité.'),
                        ])
                        ->action(function (Candidature $r, array $data): void {
                            $previous = $r->statut;
                            $r->update([
                                'statut' => Candidature::STATUT_CANDIDAT,
                                'submitted_at' => $r->submitted_at ?? now(),
                            ]);
                            activity('candidatures')->causedBy(auth()->user())->performedOn($r)
                                ->withProperties(['from' => $previous, 'to' => 'candidat', 'motif' => $data['motif']])
                                ->event('candidature_status_changed')
                                ->log('Statut basculé manuellement vers candidat');
                        }),
                    Tables\Actions\Action::make('accept')
                        ->label('Accepter')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Candidature $r) => auth()->user()?->can('candidature.accept')
                            && $r->statut === Candidature::STATUT_CANDIDAT)
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('internal_comment')
                                ->label('Commentaire interne (optionnel)')
                                ->maxLength(500),
                        ])
                        ->action(function (Candidature $r, array $data): void {
                            $r->update([
                                'statut' => Candidature::STATUT_ACCEPTE,
                                'decided_at' => now(),
                            ]);
                            CandidatureAccepted::dispatch($r->fresh(), $data['internal_comment'] ?? null);
                            activity('candidatures')->causedBy(auth()->user())->performedOn($r)
                                ->event('candidature_accepted')
                                ->withProperties(['internal_comment' => $data['internal_comment'] ?? null])
                                ->log('Candidature acceptée');
                        }),
                    Tables\Actions\Action::make('refuse')
                        ->label('Refuser')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Candidature $r) => auth()->user()?->can('candidature.refuse')
                            && $r->statut === Candidature::STATUT_CANDIDAT)
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('motif')
                                ->label('Motif du refus (transmis au candidat)')
                                ->required()
                                ->minLength(20)
                                ->maxLength(800),
                        ])
                        ->action(function (Candidature $r, array $data): void {
                            $r->update([
                                'statut' => Candidature::STATUT_REFUSE,
                                'decided_at' => now(),
                            ]);
                            CandidatureRefused::dispatch($r->fresh(), $data['motif']);
                            activity('candidatures')->causedBy(auth()->user())->performedOn($r)
                                ->event('candidature_refused')
                                ->withProperties(['motif' => $data['motif']])
                                ->log('Candidature refusée');
                        }),
                    Tables\Actions\Action::make('markPaid')
                        ->label('Marquer frais payés')
                        ->icon('heroicon-o-banknotes')
                        ->color('warning')
                        ->visible(fn (Candidature $r) => auth()->user()?->can('candidature.mark_paid') && ! $r->frais_paye)
                        ->form([
                            Forms\Components\Select::make('mode_paiement')->required()->options([
                                'cremincam_agence' => 'Agence CREMINCAM',
                                'virement' => 'Virement bancaire',
                                'especes' => 'Espèces',
                            ]),
                            Forms\Components\TextInput::make('reference_paiement')->required()->maxLength(100),
                            Forms\Components\DatePicker::make('date_paiement')->required()->native(false),
                        ])
                        ->action(function (Candidature $r, array $data): void {
                            $r->update([
                                'frais_paye' => true,
                                'mode_paiement' => $data['mode_paiement'],
                                'reference_paiement' => $data['reference_paiement'],
                                'date_paiement' => $data['date_paiement'],
                            ]);
                            activity('candidatures')->causedBy(auth()->user())->performedOn($r)
                                ->event('frais_marked_paid')
                                ->withProperties($data)
                                ->log('Frais marqués comme payés');
                        }),
                    Tables\Actions\Action::make('withdraw')
                        ->label('Retirer (administratif)')
                        ->icon('heroicon-o-archive-box-x-mark')
                        ->color('gray')
                        ->visible(fn () => auth()->user()?->can('candidature.withdraw'))
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('motif')->required()->minLength(20),
                        ])
                        ->action(function (Candidature $r, array $data): void {
                            $r->update(['withdrawn_at' => now()]);
                            activity('candidatures')->causedBy(auth()->user())->performedOn($r)
                                ->event('candidature_withdrawn_admin')
                                ->withProperties(['motif' => $data['motif']])
                                ->log('Candidature retirée administrativement');
                        }),
                ])->label('Actions')->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->bulkActions([
                // Cœur du travail de guichet : l'agent sélectionne tous les
                // dossiers reçus dans la journée et les pointe en une fois,
                // éventuellement le lendemain (d'où la date saisissable).
                Tables\Actions\BulkAction::make('markDepotPhysiqueBulk')
                    ->label('Marquer les dossiers comme reçus')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->can('candidature.mark_depot_physique'))
                    ->modalHeading('Réception groupée de dossiers papier')
                    ->modalSubmitActionLabel('Enregistrer les réceptions')
                    ->form([
                        Forms\Components\DatePicker::make('recu_le')
                            ->label('Date de réception effective')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->maxDate(now())
                            ->default(now())
                            ->helperText('Appliquée à tous les dossiers sélectionnés.'),
                        Forms\Components\TextInput::make('observation')
                            ->label('Observation commune (facultatif)')
                            ->maxLength(255),
                    ])
                    ->action(function ($records, array $data): void {
                        $service = app(DepotPhysiqueService::class);
                        $agent = auth()->user();
                        $recuLe = Carbon::parse($data['recu_le']);

                        $marques = [];
                        $ignores = [];

                        foreach ($records as $r) {
                            // Déjà pointé ou jamais soumis en ligne : on saute
                            // sans faire échouer tout le lot.
                            if ($r->depot_physique_at !== null || $r->submitted_at === null) {
                                $ignores[] = $r->numero_dossier;

                                continue;
                            }

                            $service->marquer($r, $recuLe, $agent, $data['observation'] ?? null);
                            $marques[] = $r->numero_dossier;
                        }

                        Notification::make()
                            ->title(count($marques).' dossier(s) marqué(s) comme reçus')
                            ->body($ignores === []
                                ? null
                                : count($ignores).' dossier(s) ignoré(s) : déjà pointés ou non soumis en ligne.')
                            ->success()
                            ->send();

                        activity('candidatures')->causedBy($agent)
                            ->event('depot_physique_marked_bulk')
                            ->withProperties([
                                'recu_le' => $recuLe->toDateString(),
                                'count' => count($marques),
                                'numeros' => $marques,
                                'ignores' => $ignores,
                            ])
                            ->log('Réception groupée de dossiers physiques ('.count($marques).')');
                    }),
                Tables\Actions\BulkAction::make('regenerateRecipisseBulk')
                    ->label('Régénérer les récépissés')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn () => auth()->user()?->can('candidature.regenerate_recipisse'))
                    ->requiresConfirmation()
                    ->modalHeading('Régénération groupée des récépissés')
                    ->modalDescription('Chaque récépissé sélectionné est refabriqué à partir des données actuelles du dossier. Les dossiers jamais soumis sont ignorés.')
                    ->modalSubmitActionLabel('Régénérer')
                    ->action(function ($records): void {
                        $refaits = [];
                        $ignores = [];
                        $echecs = [];

                        foreach ($records as $r) {
                            if ($r->submitted_at === null) {
                                $ignores[] = $r->numero_dossier;

                                continue;
                            }

                            try {
                                static::regenerateRecipisse($r);
                                $refaits[] = $r->numero_dossier;
                            } catch (\Throwable $e) {
                                // Un dossier en échec ne doit pas faire tomber
                                // tout le lot : on isole et on rend compte.
                                Log::error('recipisse_regeneration_failed', [
                                    'numero_dossier' => $r->numero_dossier,
                                    'exception' => $e::class,
                                    'message' => $e->getMessage(),
                                ]);
                                $echecs[] = $r->numero_dossier;
                            }
                        }

                        $details = array_filter([
                            $ignores === [] ? null : count($ignores).' ignoré(s) : dossier jamais soumis.',
                            $echecs === [] ? null : count($echecs).' en échec : '.implode(', ', $echecs).'.',
                        ]);

                        $notification = Notification::make()
                            ->title(count($refaits).' récépissé(s) régénéré(s)')
                            ->body($details === [] ? null : implode(' ', $details));

                        $echecs === [] ? $notification->success() : $notification->warning();
                        $notification->send();
                    }),
                Tables\Actions\BulkAction::make('exportCsv')
                    ->label('Exporter en CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => auth()->user()?->can('candidature.export_csv'))
                    ->action(fn ($records) => static::exportCsv($records)),
                Tables\Actions\BulkAction::make('markPaidBulk')
                    ->label('Marquer frais payés')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn () => auth()->user()?->can('candidature.mark_paid'))
                    ->form([
                        Forms\Components\Select::make('mode_paiement')->required()->options([
                            'cremincam_agence' => 'Agence CREMINCAM', 'virement' => 'Virement', 'especes' => 'Espèces',
                        ]),
                        Forms\Components\DatePicker::make('date_paiement')->required()->native(false),
                    ])
                    ->action(function ($records, array $data): void {
                        $count = 0;
                        foreach ($records as $r) {
                            if ($r->frais_paye) {
                                continue;
                            }
                            $r->update(array_merge($data, ['frais_paye' => true]));
                            $count++;
                        }
                        activity('candidatures')->causedBy(auth()->user())
                            ->event('frais_marked_paid_bulk')
                            ->withProperties(['count' => $count, 'numeros' => $records->pluck('numero_dossier')->all()])
                            ->log("Frais marqués payés en bulk ({$count} dossiers)");
                    }),
                Tables\Actions\BulkAction::make('acceptBulk')
                    ->label('Accepter en bulk')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->can('candidature.bulk_decision'))
                    ->requiresConfirmation()
                    ->action(function ($records): void {
                        $accepted = [];
                        foreach ($records as $r) {
                            if ($r->statut !== Candidature::STATUT_CANDIDAT) {
                                continue;
                            }
                            $r->update(['statut' => Candidature::STATUT_ACCEPTE, 'decided_at' => now()]);
                            CandidatureAccepted::dispatch($r->fresh(), null);
                            $accepted[] = $r->numero_dossier;
                        }
                        activity('candidatures')->causedBy(auth()->user())
                            ->event('candidature_accepted_bulk')
                            ->withProperties(['count' => count($accepted), 'numeros' => $accepted])
                            ->log('Acceptation en bulk');
                    }),
                Tables\Actions\BulkAction::make('refuseBulk')
                    ->label('Refuser en bulk')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn () => auth()->user()?->can('candidature.bulk_decision'))
                    ->form([
                        Forms\Components\Textarea::make('motif')
                            ->label('Motif unique appliqué à tous les dossiers')
                            ->required()->minLength(20)->maxLength(800),
                    ])
                    ->action(function ($records, array $data): void {
                        $refused = [];
                        foreach ($records as $r) {
                            if ($r->statut !== Candidature::STATUT_CANDIDAT) {
                                continue;
                            }
                            $r->update(['statut' => Candidature::STATUT_REFUSE, 'decided_at' => now()]);
                            CandidatureRefused::dispatch($r->fresh(), $data['motif']);
                            $refused[] = $r->numero_dossier;
                        }
                        activity('candidatures')->causedBy(auth()->user())
                            ->event('candidature_refused_bulk')
                            ->withProperties(['count' => count($refused), 'numeros' => $refused, 'motif' => $data['motif']])
                            ->log('Refus en bulk');
                    }),
            ]);
    }

    /**
     * Refabrique le récépissé d'un dossier et trace l'opération.
     *
     * Utilisé par l'action unitaire et l'action groupée — les récépissés émis
     * avant la correction de l'embarquement photo sont sortis sans
     * photographie et doivent pouvoir être rattrapés depuis l'admin.
     */
    public static function regenerateRecipisse(Candidature $candidature): void
    {
        $previousPath = $candidature->recipisse_pdf_path;

        $path = app(RecipisseService::class)->regenerate($candidature);

        activity('candidatures')
            ->causedBy(auth()->user())
            ->performedOn($candidature)
            ->withProperties([
                'previous_path' => $previousPath,
                'path' => $path,
                'had_photo' => $candidature->photo_path !== null,
            ])
            ->event('recipisse_regenerated')
            ->log('Récépissé régénéré depuis l\'admin');
    }

    /** Export CSV streamé d'une collection de Candidatures. */
    public static function exportCsv($records): StreamedResponse
    {
        $count = $records->count();
        activity('candidatures')->causedBy(auth()->user())
            ->event('candidatures_exported_csv')
            ->withProperties(['count' => $count, 'numeros' => $records->pluck('numero_dossier')->all()])
            ->log("Export CSV de {$count} candidatures");

        $filename = 'candidatures-'.now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($records): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 (Excel)
            fputcsv($out, [
                'numero_dossier', 'nom', 'prenom', 'phone_e164', 'email', 'annee_academique',
                'date_naissance', 'region', 'departement', 'specialite', 'type_etude',
                'statut', 'submitted_at', 'frais_paye', 'mode_paiement', 'reference_paiement',
            ]);
            foreach ($records as $r) {
                fputcsv($out, [
                    $r->numero_dossier, $r->nom, $r->prenom, $r->phone_e164, $r->email, $r->campagne?->nom,
                    optional($r->date_naissance)->toDateString(), $r->region, $r->departement,
                    $r->specialite, $r->type_etude,
                    $r->statut, optional($r->submitted_at)->toDateTimeString(),
                    $r->frais_paye ? 'oui' : 'non', $r->mode_paiement, $r->reference_paiement,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCandidatures::route('/'),
            'view' => Pages\ViewCandidature::route('/{record}'),
            'edit' => Pages\EditCandidature::route('/{record}/edit'),
        ];
    }
}
