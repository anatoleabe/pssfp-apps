<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Auditeur;
use App\Models\InscriptionAnnuelle;
use App\Models\SuspensionAuditeur;
use App\Models\Versement;
use App\Services\Scolarite\AnneeEnCours;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\StatutAuditeur;
use App\Services\Scolarite\SuspensionsAuditeurs;
use App\Support\CandidatureObjectStorage;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Suspension d'un auditeur pour l'année en cours, sur sa demande écrite (spec module-4-scolarite-suspension) :
 * l'administrateur joint la demande, puis confirme sur un récapitulatif. Levée, reprise sur eCampus et consultation
 * de la demande depuis la fiche.
 */
final class SuspensionActions
{
    public static function suspendre(): Action
    {
        return Action::make('suspendre')
            ->label(fn (): string => 'Suspendre pour '.AnneeEnCours::annee())
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (Auditeur $record): bool => self::peutSuspendre($record))
            ->modalHeading('Suspendre l\'auditeur à sa demande')
            ->modalSubmitActionLabel('Suspendre')
            ->fillForm(fn (): array => ['effet_le' => InscriptionAnnuelle::aujourdhui()->toDateString()])
            ->steps([
                Forms\Components\Wizard\Step::make('Demande écrite')
                    ->description('Le scan signé de l\'auditeur')
                    ->schema(MessagesValidation::appliquer([
                        AuditeurActions::champJustificatif(
                            'Demande écrite de l\'auditeur (scan signé)',
                            10,
                            'Le stockage n\'a pas répondu : la demande n\'est pas enregistrée. Déposez-la à nouveau.',
                            'demande_path',
                        )->required(),
                        Forms\Components\DatePicker::make('demande_du')->label('Date de la lettre')->required()->native(false)->displayFormat('d/m/Y')
                            ->maxDate(InscriptionAnnuelle::aujourdhui()->endOfDay()->toDateTimeString()),
                        Forms\Components\DatePicker::make('effet_le')->label('Suspendu à compter du')->required()->native(false)->displayFormat('d/m/Y')
                            ->maxDate(InscriptionAnnuelle::aujourdhui()->endOfDay()->toDateTimeString()),
                        Forms\Components\Textarea::make('motif')->label('Motif indiqué dans la demande')->required()->maxLength(500)->rows(3),
                    ])),
                Forms\Components\Wizard\Step::make('Confirmation')
                    ->description('Vérifier avant de suspendre')
                    ->schema([
                        Forms\Components\Placeholder::make('recapitulatif')->hiddenLabel()
                            ->content(fn (Auditeur $record, Forms\Get $get): HtmlString => self::recapitulatif($record, $get('demande_du'), $get('effet_le'))),
                        Forms\Components\Checkbox::make('verifiee')->required()->accepted()
                            ->label('J\'ai vérifié que la demande est signée de l\'auditeur, et je confirme la suspension.')
                            ->validationMessages(['accepted' => 'Cochez la confirmation pour suspendre.']),
                    ]),
            ])
            ->action(function (Auditeur $record, array $data, Action $action): void {
                $demande = is_string($data['demande_path'] ?? null) ? $data['demande_path'] : null;
                try {
                    AuditeurActions::exigerScanDepose($demande);
                    $suspension = app(SuspensionsAuditeurs::class)->suspendre(
                        $record,
                        (string) $demande,
                        (string) ($data['demande_du'] ?? ''),
                        (string) ($data['motif'] ?? ''),
                        (string) ($data['effet_le'] ?? ''),
                        (bool) ($data['verifiee'] ?? false),
                        auth()->user(),
                    );
                } catch (InvalidArgumentException $e) {
                    AuditeurActions::supprimerScanOrphelin($demande);
                    Notification::make()->danger()->title('Auditeur non suspendu')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                self::notifierEcampus('Auditeur suspendu pour '.$suspension->annee_academique, $suspension);
            });
    }

    public static function lever(): Action
    {
        return Action::make('leverSuspension')
            ->label('Lever la suspension')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(SuspensionsAuditeurs::PERMISSION) && $record->suspensionEnCours()->exists())
            ->modalHeading('Lever la suspension')
            ->modalDescription(fn (Auditeur $record): string => self::effetsLevee($record->suspensionEnCours()->first()))
            ->modalSubmitActionLabel('Lever la suspension')
            ->form(MessagesValidation::appliquer([
                Forms\Components\Textarea::make('motif')->label('Motif de la levée')->required()->maxLength(500)->rows(3)
                    ->placeholder('Ex. : reprise de la formation demandée par l\'auditeur le …'),
            ]))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    $suspension = app(SuspensionsAuditeurs::class)->lever($record, (string) ($data['motif'] ?? ''), auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Suspension non levée')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                self::notifierEcampus('Suspension levée', $suspension);
            });
    }

    public static function reessayerEcampus(): Action
    {
        return Action::make('reessayerEcampusSuspension')
            ->label(fn (Auditeur $record): string => self::derniere($record)?->enCours() === false ? 'Rouvrir le compte eCampus' : 'Suspendre le compte eCampus')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can(SuspensionsAuditeurs::PERMISSION)
                && ($s = self::derniere($record)) !== null && SuspensionsAuditeurs::ecampusARefaire($s))
            ->requiresConfirmation()
            ->modalDescription(fn (Auditeur $record): string => 'Dernier constat : '.(self::derniere($record)?->ecampus_detail ?? '—'))
            ->action(function (Auditeur $record): void {
                $suspension = self::derniere($record);
                abort_unless($suspension !== null, 404);
                try {
                    app(SuspensionsAuditeurs::class)->reessayerEcampus($suspension, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('eCampus n\'a pas suivi')->body($e->getMessage())->send();

                    return;
                }
                Notification::make()->success()->title('Compte eCampus à jour')->body($suspension->refresh()->ecampus_detail)->send();
            });
    }

    public static function voirDemande(): Action
    {
        return Action::make('voirDemandeSuspension')
            ->label('Voir la demande de suspension')
            ->icon('heroicon-o-paper-clip')
            ->visible(fn (Auditeur $record): bool => self::peutVoir() && Versement::cheminJustificatifValide(self::derniere($record)?->demande_path))
            ->action(function (Auditeur $record) {
                $s = self::derniere($record);
                abort_unless($s !== null && self::peutVoir() && Versement::cheminJustificatifValide($s->demande_path), 403);
                activity('scolarite')->causedBy(auth()->user())->performedOn($record)->event('suspension_demande_consultee')
                    ->log('Demande de suspension consultée');
                $extension = pathinfo((string) $s->demande_path, PATHINFO_EXTENSION);

                return redirect()->away(Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl(
                    (string) $s->demande_path,
                    now()->addMinutes(30),
                    ['ResponseContentDisposition' => 'inline; filename="demande-suspension-'.$s->annee_academique.'.'.$extension.'"'],
                ));
            });
    }

    /** Ligne de la section « Statut » de la fiche : la dernière suspension, en cours ou levée. */
    public static function resume(Auditeur $record): ?string
    {
        $s = self::derniere($record);
        if ($s === null) {
            return null;
        }
        $texte = $s->annee_academique.' · demande du '.$s->demande_du->format('d/m/Y').' · suspendu le '.$s->created_at?->timezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y')
            .' par '.($s->suspenduPar?->name ?? '—').' · motif : '.$s->motif;
        if (! $s->enCours()) {
            $texte .= ' — levée le '.$s->levee_at?->timezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y').' par '.($s->leveePar?->name ?? '—').' : '.$s->levee_motif
                .($s->reprise_meme_annee ? ' (reprise dans l\'année : le reste est redevenu dû)' : ' (l\'année suspendue reste non réclamée)');
        }

        return $texte.' — eCampus : '.($s->ecampus_detail ?? '—');
    }

    private static function peutSuspendre(Auditeur $record): bool
    {
        $annee = AnneeEnCours::annee();

        return (bool) auth()->user()?->can(SuspensionsAuditeurs::PERMISSION)
            && $annee !== null
            && $record->statut === Auditeur::STATUT_ACTIF
            && $record->inscriptions()->where('annee_academique', $annee)->exists();
    }

    /** La demande peut porter des raisons personnelles : administrateurs et responsables du statut seulement. */
    private static function peutVoir(): bool
    {
        $u = auth()->user();

        return $u !== null && ($u->can(SuspensionsAuditeurs::PERMISSION) || $u->can(StatutAuditeur::PERMISSION));
    }

    private static function derniere(Auditeur $record): ?SuspensionAuditeur
    {
        return $record->suspensions()->with(['suspenduPar:id,name', 'leveePar:id,name'])->first();
    }

    private static function recapitulatif(Auditeur $record, mixed $demandeDu, mixed $effetLe): HtmlString
    {
        $annee = (string) AnneeEnCours::annee();
        $c = $record->candidature;
        $inscription = $record->inscriptions()->where('annee_academique', $annee)->first();
        $reste = $inscription?->situation()->reste ?? 0;
        $date = fn (mixed $v): string => filled($v) ? CarbonImmutable::parse((string) $v)->format('d/m/Y') : '—';
        $lignes = [
            'Auditeur' => trim(($c?->nom ?? '').' '.($c?->prenom ?? '')).' — matricule '.($c?->matricule ?? '—'),
            'Année suspendue' => $annee,
            'Demande' => 'lettre du '.$date($demandeDu).', suspension à compter du '.$date($effetLe),
        ];
        $effets = [
            'son statut passe à « Suspendu » ;',
            'son compte eCampus est suspendu : il ne peut plus s\'y connecter ;',
            'le reste dû de '.$annee.' ('.LibelleSituation::fcfa($reste).') n\'est plus réclamé ; ce qu\'il a versé reste acquis ;',
            'la suspension se lève depuis sa fiche (« Lever la suspension »), avec un motif.',
        ];

        // Styles en ligne : le panneau n'a pas de thème compilé, une classe Tailwind absente de Filament ne s'appliquerait pas.
        $html = '<dl style="margin:0;font-size:.875rem;line-height:1.6;">';
        foreach ($lignes as $libelle => $valeur) {
            $html .= '<div><dt style="display:inline;font-weight:600;">'.e($libelle).' : </dt><dd style="display:inline;margin:0;">'.e($valeur).'</dd></div>';
        }
        $html .= '</dl><p style="margin:.75rem 0 .25rem;font-size:.875rem;font-weight:600;">En confirmant :</p><ul style="margin:0;padding-inline-start:1.25rem;list-style:disc;font-size:.875rem;line-height:1.6;">';
        foreach ($effets as $effet) {
            $html .= '<li>'.e($effet).'</li>';
        }

        return new HtmlString($html.'</ul>');
    }

    private static function effetsLevee(?SuspensionAuditeur $s): string
    {
        if ($s === null) {
            return '';
        }

        return AnneeEnCours::annee() === $s->annee_academique
            ? 'Reprise dans l\'année '.$s->annee_academique.' : l\'auditeur redevient « Actif », le reste de l\'année redevient dû et son compte eCampus est rouvert.'
            : 'L\'auditeur redevient « Actif » et son compte eCampus est rouvert. L\'année suspendue ('.$s->annee_academique.') reste non réclamée.';
    }

    private static function notifierEcampus(string $titre, SuspensionAuditeur $s): void
    {
        if (SuspensionsAuditeurs::ecampusARefaire($s)) {
            Notification::make()->warning()->persistent()->title($titre.' ; eCampus n\'a pas suivi')
                ->body(($s->ecampus_detail ?? 'eCampus n\'a pas répondu.').' Réessayez depuis Dossier › « '.($s->enCours() ? 'Suspendre' : 'Rouvrir').' le compte eCampus ».')
                ->send();

            return;
        }
        Notification::make()->success()->title($titre)->body($s->ecampus_detail)->send();
    }
}
