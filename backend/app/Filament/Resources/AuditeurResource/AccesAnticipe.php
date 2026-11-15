<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditeurResource;

use App\Models\Auditeur;
use App\Models\DeblocageAcces;
use App\Models\InscriptionAnnuelle;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\DeblocagesAcces;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\MessagesAuditeurs;
use App\Services\Scolarite\ResultatDeblocage;
use App\Support\CandidatureObjectStorage;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Accès anticipé sur la fiche et la liste des auditeurs (spec module 4, lot
 * A1 §7) : débloquer, prolonger, révoquer, voir la pièce ; encadré de la
 * fiche ; colonne et filtre de la liste. Les gestes délèguent à
 * DeblocagesAcces, qui revérifie les droits.
 */
final class AccesAnticipe
{
    public const FILTRE_EN_COURS = 'en_cours';

    public const FILTRE_ECHU = 'echu';

    public static function debloquer(): Action
    {
        return Action::make('debloquerAcces')
            ->label('Débloquer l\'accès')
            ->icon('heroicon-o-lock-open')
            ->visible(fn (Auditeur $record): bool => DeblocagesAcces::typesPermis(auth()->user()) !== [] && self::deblocable($record)
                && DeblocagesAcces::peutAccorderApresRevocation($record->inscriptionCourante, auth()->user()))
            ->modalHeading('Débloquer l\'accès avant paiement')
            ->modalDescription(fn (Auditeur $record): string => 'Droits d\'inscription restant dus : '.LibelleSituation::fcfa($record->inscriptionCourante->situation()->resteInscription())
                .'. L\'auditeur reçoit ses accès par SMS et par e-mail ; il reste « non soldé » tant qu\'il n\'a pas payé, et le déblocage se régularise seul au paiement. Les codes remis restent valables sur eCampus même après l\'échéance.')
            ->modalSubmitActionLabel('Débloquer')
            ->form(fn (): array => MessagesValidation::appliquer([
                Forms\Components\Select::make('type')->label('Cas')->required()->native(false)->live()
                    ->options(fn (): array => DeblocagesAcces::typesPermis(auth()->user()))
                    // Revérifié côté serveur : un cas absent de la liste (moratoire pour l'agent) est refusé.
                    ->in(fn (): array => array_keys(DeblocagesAcces::typesPermis(auth()->user())))
                    ->default(DeblocageAcces::VIREMENT_ANNONCE)
                    ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('echeance', DeblocagesAcces::echeanceParDefaut((string) $state)?->toDateString()))
                    ->helperText(fn (): ?string => isset(DeblocagesAcces::typesPermis(auth()->user())[DeblocageAcces::MORATOIRE])
                        ? 'Prise en charge par l\'employeur : choisissez « Moratoire ou dérogation » et joignez la lettre de l\'employeur.'
                        : null),
                Forms\Components\DatePicker::make('echeance')->label('À régulariser avant le')->native(false)->displayFormat('d/m/Y')
                    ->required(fn (Forms\Get $get): bool => $get('type') !== DeblocageAcces::VIREMENT_ANNONCE)
                    ->default(DeblocagesAcces::echeanceParDefaut(DeblocageAcces::VIREMENT_ANNONCE)?->toDateString())
                    ->minDate(InscriptionAnnuelle::aujourdhui()->addDay()->toDateString())
                    // Fin de journée : le sélecteur renvoie une heure (cf. la date d'un versement).
                    ->maxDate(fn (Forms\Get $get): ?string => DeblocagesAcces::echeanceMax((string) $get('type'))?->endOfDay()->toDateTimeString())
                    ->helperText(fn (Forms\Get $get): string => $get('type') === DeblocageAcces::MORATOIRE
                        ? 'Obligatoire, '.DeblocagesAcces::JOURS_MAX[DeblocageAcces::MORATOIRE].' jours au plus.'
                        : DeblocagesAcces::JOURS_DEFAUT_VIREMENT.' jours par défaut, '.DeblocagesAcces::JOURS_MAX[DeblocageAcces::VIREMENT_ANNONCE].' jours au plus.'),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()
                    ->minLength(DeblocagesAcces::MOTIF_MIN)->maxLength(DeblocagesAcces::MOTIF_MAX)->rows(3),
                AuditeurActions::champJustificatif('Pièce (preuve du virement, demande écrite, lettre de l\'employeur)', 10,
                    'Le stockage n\'a pas répondu : la pièce n\'est pas enregistrée. Réessayez dans quelques instants.')
                    ->required(fn (Forms\Get $get): bool => $get('type') === DeblocageAcces::VIREMENT_ANNONCE),
            ], self::messages()))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    // Seule une pièce déposée par ce formulaire, dans cette requête, se rattache au déblocage.
                    if (filled($data['justificatif_path'] ?? null) && ! AuditeurActions::depose((string) $data['justificatif_path'])) {
                        throw new InvalidArgumentException('La pièce jointe est invalide : déposez-la à nouveau.');
                    }
                    $resultat = app(DeblocagesAcces::class)->accorder($record->inscriptionCourante, $data, auth()->user());
                } catch (InvalidArgumentException $e) {
                    AuditeurActions::supprimerScanOrphelin($data['justificatif_path'] ?? null);
                    Notification::make()->danger()->title('Accès non débloqué')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                self::notifier($resultat, $record);
            });
    }

    public static function prolonger(): Action
    {
        return Action::make('prolongerDeblocage')
            ->label('Prolonger l\'accès anticipé')
            ->icon('heroicon-o-calendar-days')
            ->visible(fn (Auditeur $record): bool => ($d = self::enCours($record)) !== null && DeblocagesAcces::peutProlonger($d, auth()->user()))
            ->modalHeading('Prolonger l\'accès anticipé')
            ->modalDescription(fn (Auditeur $record): string => 'Échéance actuelle : '.self::enCours($record)?->echeance->format('d/m/Y').'. '
                .(auth()->user()?->can(DeblocagesAcces::PERMISSION_EXCEPTIONNEL)
                    ? 'Nouvelle échéance à '.DeblocagesAcces::JOURS_MAX[DeblocageAcces::MORATOIRE].' jours au plus.'
                    : 'L\'agent prolonge un virement annoncé une seule fois, de '.DeblocagesAcces::PROLONGATION_AGENT_JOURS.' jours au plus.'))
            ->modalSubmitActionLabel('Prolonger')
            ->form(fn (Auditeur $record): array => MessagesValidation::appliquer([
                Forms\Components\DatePicker::make('echeance')->label('Nouvelle échéance')->required()->native(false)->displayFormat('d/m/Y')
                    ->minDate(InscriptionAnnuelle::aujourdhui()->addDay()->toDateString())
                    ->maxDate(($d = self::enCours($record)) === null ? null
                        : DeblocagesAcces::echeanceMaxProlongation($d, auth()->user())->endOfDay()->toDateTimeString()),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()
                    ->minLength(DeblocagesAcces::MOTIF_MIN)->maxLength(DeblocagesAcces::MOTIF_MAX)->rows(3),
            ], self::messages()))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    app(DeblocagesAcces::class)->prolonger(self::enCours($record) ?? throw new InvalidArgumentException('Aucun déblocage en cours.'),
                        $data['echeance'] ?? null, $data['motif'] ?? null, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Accès anticipé non prolongé')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                Notification::make()->success()->title('Accès anticipé prolongé')->send();
            });
    }

    public static function revoquer(): Action
    {
        return Action::make('revoquerDeblocage')
            ->label('Révoquer l\'accès anticipé')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (Auditeur $record): bool => self::enCours($record) !== null && (bool) auth()->user()?->can(DeblocagesAcces::PERMISSION_EXCEPTIONNEL))
            ->modalHeading('Révoquer l\'accès anticipé')
            ->modalDescription('L\'accès redevient « bloqué » tant que les droits d\'inscription ne sont pas soldés : le code disparaît de l\'espace de l\'auditeur et le lien vers sa lettre se ferme. Un code déjà remis reste valable sur eCampus.')
            ->modalSubmitActionLabel('Révoquer')
            ->form(MessagesValidation::appliquer([
                Forms\Components\Textarea::make('motif')->label('Motif de la révocation')->required()
                    ->minLength(DeblocagesAcces::MOTIF_MIN)->maxLength(DeblocagesAcces::MOTIF_MAX)->rows(3),
            ], self::messages()))
            ->action(function (Auditeur $record, array $data, Action $action): void {
                try {
                    app(DeblocagesAcces::class)->revoquer(self::enCours($record) ?? throw new InvalidArgumentException('Aucun déblocage en cours.'),
                        $data['motif'] ?? null, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Accès anticipé non révoqué')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $record->refresh();
                Notification::make()->success()->title('Accès anticipé révoqué')->send();
            });
    }

    public static function voirPiece(): Action
    {
        return Action::make('voirPieceDeblocage')
            ->label('Voir la pièce de l\'accès anticipé')
            ->icon('heroicon-o-paper-clip')
            // Seuls les chemins du dossier des justificatifs sont signés (même bucket que les pièces des candidats).
            ->visible(fn (Auditeur $record): bool => (bool) auth()->user()?->can('view_auditeur')
                && DeblocageAcces::cheminPieceValide(self::enCours($record)?->justificatif_path))
            ->action(function (Auditeur $record) {
                $d = self::enCours($record);
                abort_unless($d !== null && DeblocageAcces::cheminPieceValide($d->justificatif_path) && auth()->user()?->can('view_auditeur'), 403);
                activity('scolarite')->causedBy(auth()->user())->performedOn($d)->event('deblocage_piece_consultee')->log('Pièce de l\'accès anticipé consultée');
                $extension = pathinfo((string) $d->justificatif_path, PATHINFO_EXTENSION);

                return redirect()->away(Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl(
                    (string) $d->justificatif_path,
                    now()->addMinutes(30),
                    ['ResponseContentDisposition' => 'inline; filename="piece-acces-anticipe.'.$extension.'"'],
                ));
            });
    }

    /** Encadré de la section « Accès à eCampus » de la fiche, pendant un déblocage en cours. */
    public static function encadre(): Infolists\Components\Fieldset
    {
        return Infolists\Components\Fieldset::make('Accès anticipé')->columns(3)->columnSpanFull()
            ->visible(fn (Auditeur $record): bool => self::enCours($record) !== null)
            ->schema([
                Infolists\Components\TextEntry::make('anticipe_cas')->label('Cas')->badge()->color('warning')
                    ->getStateUsing(fn (Auditeur $record): ?string => self::enCours($record)?->libelleType()),
                Infolists\Components\TextEntry::make('anticipe_echeance')->label('À régulariser avant le')
                    ->getStateUsing(fn (Auditeur $record): ?string => ($d = self::enCours($record)) === null ? null
                        : $d->echeance->format('d/m/Y').($d->estEchu() ? ' (dépassée)' : ''))
                    ->color(fn (Auditeur $record): ?string => self::enCours($record)?->estEchu() ? 'danger' : null)
                    ->weight(fn (Auditeur $record): ?string => self::enCours($record)?->estEchu() ? 'bold' : null),
                Infolists\Components\TextEntry::make('anticipe_accorde')->label('Accordé')
                    ->getStateUsing(fn (Auditeur $record): ?string => ($d = self::enCours($record)) === null ? null
                        : 'Par '.($d->accordePar?->name ?? 'un compte supprimé').', le '.$d->created_at?->timezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y à H:i')),
                Infolists\Components\TextEntry::make('anticipe_motif')->label('Motif')->columnSpan(2)
                    ->getStateUsing(fn (Auditeur $record): ?string => self::enCours($record)?->motif),
                Infolists\Components\TextEntry::make('anticipe_piece')->label('Pièce')
                    ->getStateUsing(fn (Auditeur $record): string => filled(self::enCours($record)?->justificatif_path)
                        ? 'Jointe (menu « Accès eCampus »)' : 'Aucune'),
                Infolists\Components\TextEntry::make('anticipe_relances')->label('Relances')->columnSpan(3)
                    ->getStateUsing(fn (Auditeur $record): string => self::suivi(self::enCours($record))),
            ]);
    }

    public static function colonne(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('acces_anticipe')->label('Accès anticipé')->badge()
            ->getStateUsing(fn (Auditeur $record): ?string => ($d = self::enCours($record)) === null ? null
                : $d->libelleType().' · '.$d->echeance->format('d/m/Y'))
            ->color(fn (Auditeur $record): string => self::enCours($record)?->estEchu() ? 'danger' : 'warning')
            ->placeholder('—')
            ->toggleable(isToggledHiddenByDefault: true);
    }

    public static function filtre(): Tables\Filters\SelectFilter
    {
        $parCas = collect(DeblocageAcces::TYPES)->mapWithKeys(fn (string $libelle, string $type): array => [$type => 'En cours : '.mb_strtolower($libelle)])->all();

        return Tables\Filters\SelectFilter::make('acces_anticipe')->label('Accès anticipé')
            ->options([self::FILTRE_EN_COURS => 'En cours', self::FILTRE_ECHU => 'Échu, non régularisé'] + $parCas)
            ->query(function (Builder $query, array $data): Builder {
                $valeur = $data['value'] ?? null;
                if (blank($valeur)) {
                    return $query;
                }

                return $query->whereHas('inscriptionCourante.deblocageEnCours', fn (Builder $d) => match (true) {
                    $valeur === self::FILTRE_ECHU => $d->where('echeance', '<', InscriptionAnnuelle::aujourdhui()->toDateString()),
                    isset(DeblocageAcces::TYPES[$valeur]) => $d->where('type', $valeur),
                    default => $d,
                });
            });
    }

    /** Déblocage en cours de l'inscription courante (chargé avec la fiche ou la liste). */
    public static function enCours(Auditeur $record): ?DeblocageAcces
    {
        return $record->inscriptionCourante?->deblocageEnCours;
    }

    /** Dossier ouvert, auditeur en formation, droits non soldés et rien d'en cours (aussi pour demander un moratoire, A3). */
    public static function deblocable(Auditeur $record): bool
    {
        $inscription = $record->inscriptionCourante;

        return $inscription !== null
            && $record->candidature !== null && $record->candidature->withdrawn_at === null
            && ! in_array($record->statut, MessagesAuditeurs::STATUTS_EXCLUS, true)
            && $inscription->deblocageEnCours === null
            && ! $inscription->situation()->inscriptionSoldee();
    }

    private static function suivi(?DeblocageAcces $d): string
    {
        if ($d === null) {
            return '—';
        }
        $relances = $d->relances === 0 ? 'Aucune relance'
            : $d->relances.' relance(s), la dernière le '.$d->derniere_relance_le?->timezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y');

        return $relances.($d->prolongations > 0 ? ' · prolongé '.$d->prolongations.' fois' : '');
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        return [
            'min' => 'Le motif compte au moins '.DeblocagesAcces::MOTIF_MIN.' caractères.',
            'after_or_equal' => 'L\'échéance doit être postérieure à aujourd\'hui.',
            'before_or_equal' => 'Échéance trop lointaine pour ce cas.',
            'uploaded' => 'Le fichier n\'a pas pu être envoyé : 10 Mo au plus pour la pièce.',
        ];
    }

    private static function notifier(ResultatDeblocage $resultat, Auditeur $record): void
    {
        $avant = 'À régulariser avant le '.$resultat->deblocage->echeance->format('d/m/Y').'.';
        match ($resultat->notification) {
            true => Notification::make()->success()->title('Accès débloqué')
                ->body('L\'auditeur a été prévenu par SMS et par e-mail, avec le lien vers sa lettre d\'accès. '.$avant)->send(),
            false => Notification::make()->warning()->persistent()->title('Accès débloqué')
                ->body('Ni le SMS ni l\'e-mail des accès ne sont partis : utilisez « Renvoyer le SMS et l\'e-mail ». '.$avant)->send(),
            null => Notification::make()->success()->title('Accès débloqué')
                ->body(($record->candidature !== null && AccesEcampus::etat($record->candidature) === AccesEcampus::EN_PREPARATION
                    ? 'Le compte eCampus reste à créer (prévenez l\'USI) : l\'auditeur sera prévenu dès sa création. '
                    : 'L\'auditeur avait déjà été prévenu de ses accès. ').$avant)->send(),
        };
    }
}
