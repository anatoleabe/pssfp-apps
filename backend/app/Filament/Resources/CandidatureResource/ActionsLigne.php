<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource;

use App\Events\CandidatureAccepted;
use App\Events\CandidatureRefused;
use App\Filament\Resources\AuditeurResource;
use App\Filament\Resources\CandidatureResource;
use App\Models\Candidature;
use App\Services\DepotPhysiqueService;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\DocumentUploadService;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\RecipisseService;
use App\Services\TestCandidaturePurgeService;
use App\Support\CandidatureDocumentTypeLabel;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Enums\IconPosition;
use Filament\Tables;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

/**
 * Actions par dossier de la liste des candidatures : consultation, dépôt physique, décision, matricule, espace candidat, frais, retrait.
 *
 * Extrait de CandidatureResource::table() (chantier « Socle », étape 7),
 * sans changement de comportement.
 */
final class ActionsLigne
{
    /** @return array<int, mixed> */
    public static function toutes(): array
    {
        return [
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
                    ->label(fn (Candidature $r): string => $r->recipisse_pdf_path === null
                        ? 'Générer le récépissé'
                        : 'Régénérer le récépissé')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (Candidature $r): bool => auth()->user()?->can('candidature.regenerate_recipisse') === true
                        && $r->peutAvoirRecepisse())
                    ->requiresConfirmation()
                    ->modalHeading('Régénérer le récépissé PDF')
                    ->modalDescription('Le récépissé est refabriqué à partir des données actuelles du dossier (photo comprise) et remplace le fichier existant. Le statut, les dates et le numéro de dossier ne changent pas.')
                    ->modalSubmitActionLabel('Régénérer')
                    ->action(function (Candidature $r): void {
                        CandidatureResource::regenerateRecipisse($r);

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
                    // Poserait une date de soumission sur un retenu ajouté par l'admin :
                    // il entrerait dans les dépôts, puis dans le PV du jury.
                    ->visible(fn (Candidature $r): bool => auth()->user()?->can('candidature.update_status') === true && $r->ajoute_par === null)
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
                            // La décision annulée ne doit plus s'afficher au candidat.
                            'decision_motif' => null,
                            'decision_commentaire' => null,
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
                        // Repris dans l'e-mail et dans l'espace du candidat : pas un
                        // commentaire interne, malgré le nom historique du champ.
                        Forms\Components\Textarea::make('internal_comment')
                            ->label('Commentaire transmis au candidat (optionnel)')
                            ->maxLength(500),
                    ])
                    ->action(function (Candidature $r, array $data): void {
                        $r->update([
                            'statut' => Candidature::STATUT_ACCEPTE,
                            'decided_at' => now(),
                            'decision_commentaire' => filled($data['internal_comment'] ?? null) ? $data['internal_comment'] : null,
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
                            'decision_motif' => $data['motif'],
                        ]);
                        CandidatureRefused::dispatch($r->fresh(), $data['motif']);
                        activity('candidatures')->causedBy(auth()->user())->performedOn($r)
                            ->event('candidature_refused')
                            ->withProperties(['motif' => $data['motif']])
                            ->log('Candidature refusée');
                    }),
                Tables\Actions\Action::make('attribuerMatricule')
                    ->label('Attribuer le matricule')
                    ->icon('heroicon-o-identification')
                    ->color('success')
                    ->visible(fn (Candidature $r): bool => auth()->user()?->can('candidature.matricules_moodle')
                        && $r->statut === Candidature::STATUT_ACCEPTE && $r->matricule === null && $r->withdrawn_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('Rang suivant le dernier matricule attribué dans la promotion. Une fois attribué, le matricule ne change plus.')
                    ->fillForm(fn (Candidature $r): array => ['annee' => $r->campagne === null ? null
                        : (AttributionMatricules::anneeFigee($r->campagne) ?? AttributionMatricules::anneeParDefaut($r->campagne))])
                    ->form([
                        Forms\Components\TextInput::make('annee')
                            ->label('Année du matricule (deux chiffres)')
                            ->regex('/^\d{2}$/')
                            ->helperText('Laissée vide : année de la promotion. Ignorée si la promotion a déjà des matricules (année figée).'),
                    ])
                    ->action(fn (Candidature $r, array $data) => CandidatureResource::attribuerMatricules(collect([$r]), $data['annee'] ?? null)),
                Tables\Actions\Action::make('ficheAuditeur')
                    ->label('Fiche auditeur (paiements)')
                    ->icon('heroicon-o-academic-cap')
                    ->color('gray')
                    ->visible(fn (Candidature $r): bool => $r->auditeur !== null && AuditeurResource::canView($r->auditeur))
                    ->url(fn (Candidature $r): string => AuditeurResource::getUrl('view', ['record' => $r->auditeur])),
                EspaceCandidatActions::voirEspace(Tables\Actions\Action::make('voirEspace')),
                EspaceCandidatActions::nouveauCode(Tables\Actions\Action::make('nouveauCode')),
                // Reste ici pour le comité d'admission, qui n'a pas accès au module Scolarité.
                DocumentsAuditeursActions::dossier(Tables\Actions\Action::make('lettreAdmission'), ParametresLettres::DOCUMENT_ADMISSION),
                Tables\Actions\Action::make('categorieMatricule')
                    ->label('Catégorie de matricule (EFP / EFSC)')
                    ->icon('heroicon-o-identification')
                    ->color('gray')
                    ->visible(fn (Candidature $r): bool => auth()->user()?->can('candidature.matricules_moodle')
                        && $r->statut === Candidature::STATUT_ACCEPTE && $r->matricule === null)
                    ->fillForm(fn (Candidature $r): array => ['categorie' => $r->categorie_matricule])
                    ->form([
                        Forms\Components\Radio::make('categorie')
                            ->label('Catégorie')
                            ->options([Candidature::CATEGORIE_EFP => 'EFP', Candidature::CATEGORIE_EFSC => 'EFSC'])
                            ->inline()
                            ->required(),
                    ])
                    ->action(fn (Candidature $r, array $data) => CandidatureResource::classerMatricule(collect([$r]), $data['categorie'])),
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
                // Reste ici : un retenu ajouté se corrige souvent avant son matricule, donc avant sa fiche.
                AjoutRetenuActions::corrigerTelephone(Tables\Actions\Action::make('corrigerTelephone')),
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
        ];
    }
}
