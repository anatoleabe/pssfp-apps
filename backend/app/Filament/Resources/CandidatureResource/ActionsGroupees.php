<?php

declare(strict_types=1);

namespace App\Filament\Resources\CandidatureResource;

use App\Events\CandidatureAccepted;
use App\Events\CandidatureRefused;
use App\Filament\Resources\CandidatureResource;
use App\Models\Candidature;
use App\Services\DepotPhysiqueService;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\NotificationCandidatsService;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

/**
 * Actions groupées de la liste des candidatures : pointage du dépôt physique, notification, récépissés, export, frais, décisions, matricules, lettres.
 *
 * Extrait de CandidatureResource::table() (chantier « Socle », étape 7),
 * sans changement de comportement.
 */
final class ActionsGroupees
{
    /** @return array<int, mixed> */
    public static function toutes(): array
    {
        return [
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
            // Écrire à un groupe de candidats selon les filtres en cours.
            // Réservé à admin / super_admin : le message part au nom de
            // l'institution vers des dizaines de personnes.
            Tables\Actions\BulkAction::make('notifierCandidats')
                ->label('Notifier les candidats sélectionnés')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->visible(fn (): bool => (bool) auth()->user()?->can('candidature.notify'))
                ->modalHeading('Notifier les candidats sélectionnés')
                ->modalDescription('Le message part au nom du PSSFP. Relisez l\'aperçu avant d\'envoyer : cet envoi ne se rattrape pas.')
                ->modalSubmitActionLabel('Envoyer')
                ->modalWidth('4xl')
                ->form([
                    Forms\Components\Select::make('canal')
                        ->label('Canal')
                        ->options([
                            NotificationCandidatsService::CANAL_SMS => 'SMS uniquement',
                            NotificationCandidatsService::CANAL_EMAIL => 'E-mail uniquement',
                            NotificationCandidatsService::CANAL_LES_DEUX => 'SMS et e-mail',
                        ])
                        ->default(NotificationCandidatsService::CANAL_SMS)
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('modele')
                        ->label('Partir d\'un modèle')
                        ->placeholder('Message libre')
                        ->options(fn (): array => collect((array) config('notification_candidats.modeles'))
                            ->mapWithKeys(fn (array $m, string $cle): array => [$cle => $m['libelle']])
                            ->all())
                        ->helperText('Le modèle pré-remplit les champs ci-dessous. Vous pouvez ensuite le modifier librement.')
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set): void {
                            if ($state === null || $state === '') {
                                return;
                            }
                            $modele = (array) config("notification_candidats.modeles.{$state}", []);
                            $set('sujet', $modele['sujet'] ?? '');
                            $set('corps', $modele['corps'] ?? '');
                        }),

                    Forms\Components\TextInput::make('sujet')
                        ->label('Objet de l\'e-mail')
                        ->maxLength(200)
                        ->helperText('Ignoré si le canal est « SMS uniquement ».')
                        ->visible(fn (Forms\Get $get): bool => $get('canal') !== NotificationCandidatsService::CANAL_SMS)
                        ->required(fn (Forms\Get $get): bool => $get('canal') !== NotificationCandidatsService::CANAL_SMS),

                    Forms\Components\Textarea::make('corps')
                        ->label('Message')
                        ->rows(5)
                        ->required()
                        ->maxLength(1000)
                        ->live(onBlur: true)
                        ->helperText(new HtmlString(
                            'Variables disponibles : <code>{prenom}</code> <code>{nom}</code> '
                            .'<code>{numero_dossier}</code> <code>{specialite}</code> '
                            .'<code>{date_cloture}</code> <code>{url}</code>'
                        )),

                    // L'agent décide sur des chiffres, pas sur une intuition :
                    // nombre réellement joignable, coût en SMS, et surtout
                    // destinataires hors couverture — c'est ce qui a fait
                    // échouer 7 envois lors de la relance du 3 septembre.
                    Forms\Components\Placeholder::make('apercu')
                        ->label('Aperçu et impact')
                        ->content(function (Forms\Get $get, $livewire): HtmlString {
                            $corps = (string) $get('corps');
                            if (trim($corps) === '') {
                                return new HtmlString('<em>Saisissez un message pour voir l\'aperçu.</em>');
                            }

                            $records = $livewire->getSelectedTableRecords();
                            $service = app(NotificationCandidatsService::class);
                            $a = $service->apercu($records, (string) $get('canal'), $corps);

                            $lignes = [
                                '<strong>Destinataires sélectionnés :</strong> '.$a['total'],
                            ];

                            if ($service->canalInclutSms((string) $get('canal'))) {
                                $lignes[] = '<strong>Joignables par SMS :</strong> '.$a['avec_sms']
                                    .' — coût estimé <strong>'.$a['cout_sms'].' SMS</strong>'
                                    .' ('.$a['sms_par_message'].' par message)';
                                if ($a['sms_par_message'] > 1) {
                                    $lignes[] = '<span style="color:#b45309">Message de plus de 160 caractères : '
                                        .'chaque envoi sera facturé '.$a['sms_par_message'].' SMS.</span>';
                                }
                                if ($a['sms_hors_couverture'] > 0) {
                                    $lignes[] = '<span style="color:#b91c1c"><strong>'.$a['sms_hors_couverture']
                                        .' numéro(s) hors Cameroun</strong> — la passerelle ne les dessert pas, '
                                        .'ces SMS échoueront. Privilégiez l\'e-mail pour ceux-là.</span>';
                                }
                            }

                            if ($service->canalInclutEmail((string) $get('canal'))) {
                                $lignes[] = '<strong>Joignables par e-mail :</strong> '.$a['avec_email'];
                                if ($a['sans_email'] > 0) {
                                    $lignes[] = $a['sans_email'].' candidat(s) sans adresse e-mail.';
                                }
                            }

                            $lignes[] = '<br><strong>Message tel qu\'il sera reçu par le premier candidat :</strong>';
                            $lignes[] = '<div style="padding:8px;background:#f5f3ff;border-radius:6px">'
                                .nl2br(e($a['apercu'])).'</div>';

                            return new HtmlString(implode('<br>', $lignes));
                        }),
                ])
                ->action(function ($records, array $data): void {
                    $service = app(NotificationCandidatsService::class);
                    $agent = auth()->user();

                    $rapport = $service->envoyer(
                        $records,
                        (string) $data['canal'],
                        (string) $data['corps'],
                        $data['sujet'] ?? null,
                        $agent,
                    );

                    $corpsNotif = [];
                    if ($rapport['sms_envoyes'] > 0 || $rapport['sms_echecs'] > 0) {
                        $corpsNotif[] = "SMS : {$rapport['sms_envoyes']} envoyé(s), {$rapport['sms_echecs']} en échec.";
                    }
                    if ($rapport['emails_envoyes'] > 0 || $rapport['emails_echecs'] > 0) {
                        $corpsNotif[] = "E-mails : {$rapport['emails_envoyes']} envoyé(s), {$rapport['emails_echecs']} en échec.";
                    }
                    if ($rapport['ignores'] > 0) {
                        $corpsNotif[] = "{$rapport['ignores']} candidat(s) sans coordonnée exploitable.";
                    }

                    $enEchec = $rapport['sms_echecs'] + $rapport['emails_echecs'];

                    Notification::make()
                        ->title($enEchec === 0 ? 'Notification envoyée' : 'Notification envoyée avec des échecs')
                        ->body(implode(' ', $corpsNotif))
                        ->{$enEchec === 0 ? 'success' : 'warning'}()
                        ->persistent()
                        ->send();

                    activity('candidatures')->causedBy($agent)
                        ->event('candidature_notification_bulk')
                        ->withProperties([
                            'canal' => $data['canal'],
                            'destinataires' => $records->count(),
                            'sms_envoyes' => $rapport['sms_envoyes'],
                            'emails_envoyes' => $rapport['emails_envoyes'],
                            'echecs' => $enEchec,
                        ])
                        ->log('Notification groupée de candidats ('.$records->count().' sélectionné(s))');
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
                            CandidatureResource::regenerateRecipisse($r);
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
                ->action(fn ($records) => CandidatureResource::exportCsv($records)),
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
                        $r->update(['statut' => Candidature::STATUT_ACCEPTE, 'decided_at' => now(), 'decision_commentaire' => null]);
                        CandidatureAccepted::dispatch($r->fresh(), null);
                        $accepted[] = $r->numero_dossier;
                    }
                    activity('candidatures')->causedBy(auth()->user())
                        ->event('candidature_accepted_bulk')
                        ->withProperties(['count' => count($accepted), 'numeros' => $accepted])
                        ->log('Acceptation en bulk');
                }),
            Tables\Actions\BulkAction::make('attribuerMatriculesBulk')
                ->label('Attribuer les matricules')
                ->icon('heroicon-o-identification')
                ->color('success')
                ->visible(fn () => auth()->user()?->can('candidature.matricules_moodle'))
                ->requiresConfirmation()
                ->modalDescription('Seuls les retenus encore sans matricule sont numérotés, dans l\'ordre alphabétique, à la suite des matricules existants de leur promotion. Les autres dossiers sélectionnés sont ignorés.')
                ->form([
                    Forms\Components\TextInput::make('annee')
                        ->label('Année du matricule (deux chiffres)')
                        ->regex('/^\d{2}$/')
                        ->helperText('Laissée vide : année de la promotion. Ignorée si la promotion a déjà des matricules (année figée).'),
                ])
                ->deselectRecordsAfterCompletion()
                ->action(fn ($records, array $data) => CandidatureResource::attribuerMatricules($records, $data['annee'] ?? null)),
            DocumentsAuditeursActions::selection(Tables\Actions\BulkAction::make('lettresAdmissionBulk'), ParametresLettres::DOCUMENT_ADMISSION),
            Tables\Actions\BulkAction::make('categorieMatriculeBulk')
                ->label('Catégorie de matricule (EFP / EFSC)')
                ->icon('heroicon-o-identification')
                ->color('gray')
                ->visible(fn () => auth()->user()?->can('candidature.matricules_moodle'))
                ->modalDescription('S\'applique aux seuls retenus encore sans matricule ; les autres dossiers sélectionnés sont ignorés.')
                ->form([
                    Forms\Components\Radio::make('categorie')
                        ->label('Catégorie')
                        ->options([Candidature::CATEGORIE_EFP => 'EFP', Candidature::CATEGORIE_EFSC => 'EFSC'])
                        ->inline()
                        ->required(),
                ])
                ->action(fn ($records, array $data) => CandidatureResource::classerMatricule($records, $data['categorie'])),
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
                        $r->update(['statut' => Candidature::STATUT_REFUSE, 'decided_at' => now(), 'decision_motif' => $data['motif']]);
                        CandidatureRefused::dispatch($r->fresh(), $data['motif']);
                        $refused[] = $r->numero_dossier;
                    }
                    activity('candidatures')->causedBy(auth()->user())
                        ->event('candidature_refused_bulk')
                        ->withProperties(['count' => count($refused), 'numeros' => $refused, 'motif' => $data['motif']])
                        ->log('Refus en bulk');
                }),
        ];
    }
}
