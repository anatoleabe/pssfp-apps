<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\CandidatureResource\ActionsGroupees;
use App\Filament\Resources\CandidatureResource\ActionsLigne;
use App\Filament\Resources\CandidatureResource\AjoutRetenuActions;
use App\Filament\Resources\CandidatureResource\ComptesEcampusActions;
use App\Filament\Resources\CandidatureResource\Pages;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DepartementCameroun;
use App\Models\Pays;
use App\Models\RegionCameroun;
use App\Services\CandidatureService;
use App\Services\DocumentsAuditeurs\LettresAdmissionSignees;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\RecipisseService;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Resource Filament Candidature — coeur du module 5 admin (PR D).
 *
 * Particularités :
 * - Pas de création via panel (canCreate = false, cf. arbitrage D PR D), sauf
 *   l'ajout d'un retenu, par une action dédiée (AjoutRetenuActions).
 * - Édition lecture seule pour les dossiers décidés sauf super_admin (P-min-1).
 * - Champs systèmes (uuid, numero_dossier, phone_e164, user_id, statut, dates de
 *   transition, recipisse_*) sont disabled() dans le form pour empêcher leur
 *   modification via Filament (ajout 5 PR D).
 * - Filtres persistés en session pour confort UX du comité.
 */
#[BelongsToModule('admissions')]
class CandidatureResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Candidature::class;

    protected static ?string $modelLabel = 'Candidature';

    protected static ?string $pluralModelLabel = 'Candidatures';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = GroupeNavigation::ADMISSIONS;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'numero_dossier';

    /** Dossiers en examen, pour qui peut décider. */
    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can('candidature.accept')) {
            return null;
        }
        $n = static::getEloquentQuery()
            ->where('statut', Candidature::STATUT_CANDIDAT)
            ->whereNull('withdrawn_at')
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Dossiers en examen';
    }

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
                    Forms\Components\TextInput::make('statut')->disabled()
                        ->formatStateUsing(fn (?string $state): ?string => Candidature::STATUTS[$state ?? ''] ?? $state),
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
                    Forms\Components\Select::make('statut_actuel')->options(fn (): array => (array) config('statuts_actuels')),
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
                // user.roles : visibilité des boutons « Voir son espace » et « Nouveau code ».
                ->with(['campagne', 'user.roles', 'auditeur.inscriptionCourante' => fn ($q) => $q->with('bareme')->withSum('versementsValides', 'montant')])
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
                    ->color('primary')
                    ->description(fn (Candidature $r): ?string => $r->ajoute_par !== null ? 'Ajouté par l\'admin' : null),
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
                    ->formatStateUsing(fn (string $state): string => Candidature::STATUTS[$state] ?? $state)
                    ->sortable()
                    ->color(fn (string $state) => match ($state) {
                        'postulant' => 'warning',
                        'candidat' => 'info',
                        'accepte' => 'success',
                        'refuse' => 'danger',
                        default => 'gray',
                    }),
                // Retenus seulement : matricule attribué, ou catégorie en attente.
                Tables\Columns\TextColumn::make('matricule')
                    ->label('Matricule')
                    ->state(fn (Candidature $r): ?string => $r->statut !== Candidature::STATUT_ACCEPTE ? null
                        : ($r->matricule ?? $r->categorie_matricule.' · à attribuer'))
                    ->color(fn (Candidature $r): string => $r->matricule === null ? 'gray' : 'success')
                    ->badge()
                    ->copyable()
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ecampus_statut')
                    ->label('eCampus')
                    ->state(fn (Candidature $r): ?string => ComptesEcampusActions::libelle($r))
                    ->color(fn (Candidature $r): string => ComptesEcampusActions::couleur($r))
                    ->tooltip(fn (Candidature $r): ?string => $r->ecampus_detail)
                    ->badge()
                    ->visible(fn (): bool => ComptesEcampus::actif() && (bool) auth()->user()?->can('candidature.matricules_moodle'))
                    ->toggleable(),
                Tables\Columns\IconColumn::make('frais_paye')->label('Frais')
                    ->boolean()->trueIcon('heroicon-o-check-circle')->falseIcon('heroicon-o-x-circle')
                    ->tooltip(fn (Candidature $r): string => $r->frais_paye ? 'Frais payés' : ($r->ajoute_par !== null ? 'Sans objet : retenu ajouté par l\'admin' : 'Frais non payés')),
                // Frais d'inscription des retenus (distincts des frais de dossier) :
                // condition de la remise du code d'accès à l'auditeur.
                Tables\Columns\IconColumn::make('lettre_admission_path')->label('Lettre')
                    ->state(fn (Candidature $r): ?bool => LettresAdmissionSignees::depotPossible($r) ? filled($r->lettre_admission_path) : null)
                    ->boolean()
                    ->tooltip(fn (Candidature $r): ?string => LettresAdmissionSignees::depotPossible($r)
                        ? (filled($r->lettre_admission_path) ? 'Lettre signée déposée' : 'Lettre signée à déposer')
                        : null)
                    ->toggleable(),
                // ADR-0009 : rendre l'absence de photo lisible d'un coup d'œil
                // sur la liste, et pas seulement derrière un filtre.
                Tables\Columns\IconColumn::make('photo_path')
                    ->label('Photo')
                    ->boolean()
                    ->state(fn (Candidature $r): bool => $r->photo_path !== null)
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->falseColor('warning')
                    ->tooltip(fn (Candidature $r): string => $r->photo_path !== null
                        ? 'Photo fournie'
                        : 'Photo manquante — à réclamer au candidat'),
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
                Tables\Filters\SelectFilter::make('statut')->multiple()->options(Candidature::STATUTS),
                Tables\Filters\SelectFilter::make('region')
                    ->options(fn () => RegionCameroun::orderBy('order')->pluck('nom', 'code')),
                Tables\Filters\TernaryFilter::make('frais_paye')
                    ->label('Frais payés')
                    ->placeholder('Tous')
                    ->trueLabel('Payés uniquement')
                    ->falseLabel('Non payés uniquement')
                    // Un retenu ajouté par l'admin n'a pas de frais de dossier à
                    // payer : il ne doit pas recevoir la relance de ce filtre.
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('frais_paye', true),
                        false: fn (Builder $query): Builder => $query->where('frais_paye', false)->whereNull('ajoute_par'),
                    ),
                Tables\Filters\SelectFilter::make('ecampus')
                    ->label('Compte eCampus')
                    ->visible(fn (): bool => ComptesEcampus::actif() && (bool) auth()->user()?->can('candidature.matricules_moodle'))
                    ->options([
                        'a_traiter' => 'À créer ou à reprendre',
                        'ecart' => 'Différence avec eCampus',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'a_traiter' => $query
                            ->where('statut', Candidature::STATUT_ACCEPTE)
                            ->whereNull('withdrawn_at')
                            ->whereNotNull('matricule')
                            ->where(fn (Builder $q) => $q->whereNull('ecampus_statut')
                                ->orWhereIn('ecampus_statut', [ComptesEcampus::ECHEC, ComptesEcampus::COHORTE_A_FINIR])),
                        'ecart' => $query->where('ecampus_statut', ComptesEcampus::EXISTANT)->whereNotNull('ecampus_detail'),
                        default => $query,
                    }),
                Tables\Filters\TernaryFilter::make('ajoute_admin')
                    ->label('Origine du dossier')
                    ->placeholder('Tous')
                    ->trueLabel('Ajoutés par l\'admin')
                    ->falseLabel('Déposés sur le portail')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('ajoute_par'),
                        false: fn (Builder $query): Builder => $query->whereNull('ajoute_par'),
                    ),
                Tables\Filters\TernaryFilter::make('lettre_signee')
                    ->label('Lettre d\'admission signée')
                    ->placeholder('Toutes')
                    ->trueLabel('Déposée')
                    ->falseLabel('Retenus sans lettre')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('lettre_admission_path'),
                        false: fn (Builder $query): Builder => $query
                            ->where('statut', Candidature::STATUT_ACCEPTE)
                            ->whereNull('withdrawn_at')
                            ->whereNotNull('matricule')
                            ->whereNull('lettre_admission_path'),
                    ),
                // ADR-0009 : la photo ne bloquant plus la soumission, des
                // dossiers soumis circulent sans photo. Ce filtre est le seul
                // moyen pour la scolarité de voir qui relancer — sans lui, la
                // charge transférée du candidat vers l'administration serait
                // aveugle. Exprimable en SQL pur, contrairement au filtre
                // voisin : deux colonnes suffisent.
                Tables\Filters\Filter::make('soumis_sans_photo')
                    ->label('Soumis sans photo')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('submitted_at')
                        ->whereNull('photo_path'))
                    ->toggle(),
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
            ->actions(ActionsLigne::toutes())
            ->bulkActions(ActionsGroupees::toutes());
    }

    /**
     * Attribue un matricule aux dossiers choisis dans l'admin, campagne par
     * campagne, par le même service que l'attribution globale (verrou,
     * unicité, journal). Année figée de la promotion si elle existe.
     *
     * @param  iterable<Candidature>  $records
     *
     * @internal Appelée par ActionsLigne et ActionsGroupees.
     */
    public static function attribuerMatricules(iterable $records, ?string $annee): void
    {
        $user = auth()->user();
        abort_unless($user?->can('candidature.matricules_moodle'), 403);

        $attribues = 0;
        $ids = [];
        $erreurs = [];
        foreach (collect($records)->groupBy('campagne_id') as $dossiers) {
            $campagne = CampagneCandidature::query()->find($dossiers->first()->campagne_id);
            if ($campagne === null || ! $user->can('view', $campagne)) {
                continue;
            }
            $anneeCampagne = AttributionMatricules::anneeFigee($campagne)
                ?? (filled($annee) ? (string) $annee : AttributionMatricules::anneeParDefaut($campagne));
            try {
                $resultat = app(AttributionMatricules::class)
                    ->attribuer($campagne, $anneeCampagne, $user, $dossiers->pluck('id')->map(fn ($id): int => (int) $id)->all());
                $attribues += $resultat['attribues'];
                $ids = [...$ids, ...$resultat['ids']];
            } catch (InvalidArgumentException|RuntimeException $e) {
                $erreurs[] = $e->getMessage();
            }
        }

        $bilan = app(ComptesEcampus::class)->apresAttribution($ids, $user);

        $notification = Notification::make()
            ->title($attribues === 0 ? 'Aucun matricule attribué' : "{$attribues} matricule(s) attribué(s)")
            ->body(trim(($erreurs === [] ? 'Les dossiers non retenus ou déjà numérotés sont ignorés.' : implode(' ', $erreurs))
                .' '.ComptesEcampusActions::phrase($bilan)));
        match (true) {
            $erreurs !== [] => $notification->danger(),
            ! ComptesEcampusActions::sansIncident($bilan) => $notification->warning(),
            default => $notification->success(),
        };
        $notification->send();
    }

    /**
     * Classe des retenus en EFP ou EFSC avant l'attribution des matricules.
     * Un matricule attribué ne change plus : ces dossiers-là sont ignorés.
     *
     * @param  iterable<Candidature>  $records
     *
     * @internal Appelée par ActionsLigne et ActionsGroupees.
     */
    public static function classerMatricule(iterable $records, string $categorie): void
    {
        abort_unless(auth()->user()?->can('candidature.matricules_moodle'), 403);
        abort_unless(in_array($categorie, [Candidature::CATEGORIE_EFP, Candidature::CATEGORIE_EFSC], true), 422);

        $classes = [];
        foreach ($records as $r) {
            // Mise à jour conditionnelle : si l'attribution vient de passer, le
            // matricule n'est plus nul et la catégorie ne bouge pas.
            $modifie = Candidature::query()->whereKey($r->getKey())
                ->where('statut', Candidature::STATUT_ACCEPTE)
                ->whereNull('matricule')
                ->update(['categorie_matricule' => $categorie]);
            if ($modifie > 0) {
                $classes[] = $r;
            }
        }

        if ($classes !== []) {
            $journal = activity('candidatures')->causedBy(auth()->user())
                ->event('categorie_matricule')
                ->withProperties(['categorie' => $categorie, 'numeros' => array_map(fn (Candidature $c): string => (string) $c->numero_dossier, $classes)]);
            if (count($classes) === 1) {
                $journal->performedOn($classes[0]);
            }
            $journal->log("Catégorie de matricule {$categorie}");
        }

        Notification::make()->success()
            ->title(count($classes).' retenu(s) classé(s) '.$categorie)
            ->send();
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
