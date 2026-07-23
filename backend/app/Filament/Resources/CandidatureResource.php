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
use App\Services\DocumentUploadService;
use App\Services\RecipisseService;
use App\Services\TestCandidaturePurgeService;
use App\Support\CandidatureDocumentTypeLabel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\IconPosition;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
                        'M.' => 'M.', 'Mme' => 'Mme', 'Mlle' => 'Mlle',
                    ]),
                    Forms\Components\TextInput::make('nom')->maxLength(100),
                    Forms\Components\TextInput::make('prenom')->maxLength(100),
                    Forms\Components\TextInput::make('epouse')->label('Nom de jeune fille')->maxLength(100),
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
            ->defaultSort('submitted_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('campagne')->withCount('documents'))
            ->columns([
                Tables\Columns\TextColumn::make('numero_dossier')
                    ->label('N° dossier')
                    ->searchable()
                    ->copyable()
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('nom_complet')
                    ->label('Candidat')
                    ->wrap()
                    ->state(fn (Candidature $r): string => trim("{$r->prenom} {$r->nom}"))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('nom', 'ilike', "%{$search}%")
                            ->orWhere('prenom', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    }),
                Tables\Columns\TextColumn::make('phone_e164')->label('Téléphone')->searchable(),
                Tables\Columns\TextColumn::make('campagne.nom')->label('Année académique')->wrap()->toggleable(),
                Tables\Columns\TextColumn::make('specialite')->limit(28)->toggleable(),
                Tables\Columns\TextColumn::make('region')->label('Région')->toggleable(),
                Tables\Columns\TextColumn::make('statut')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'postulant' => 'warning',
                        'candidat' => 'info',
                        'accepte' => 'success',
                        'refuse' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('frais_paye')->label('Frais')
                    ->boolean()->trueIcon('heroicon-o-check-circle')->falseIcon('heroicon-o-x-circle'),
                Tables\Columns\TextColumn::make('documents_count')
                    ->label('Pièces')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->formatStateUsing(fn (int $state): string => (string) $state)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('submitted_at')->label('Soumis le')
                    ->dateTime('d/m/Y H:i')->sortable()->toggleable(),
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
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('viewRecipisse')
                    ->label('Récépissé PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->iconPosition(IconPosition::Before)
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
                    ->label(fn (Candidature $r): string => 'Pièces ('.($r->documents_count ?? $r->documents()->count()).')')
                    ->icon('heroicon-o-paper-clip')
                    ->iconPosition(IconPosition::Before)
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
                Tables\Actions\ActionGroup::make([
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
