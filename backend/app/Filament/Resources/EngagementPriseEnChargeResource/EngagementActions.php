<?php

declare(strict_types=1);

namespace App\Filament\Resources\EngagementPriseEnChargeResource;

use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\EngagementPriseEnChargeResource;
use App\Models\Auditeur;
use App\Models\EngagementPriseEnCharge;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use App\Services\Scolarite\DeblocagesAcces;
use App\Services\Scolarite\EngagementsPriseEnCharge;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\ReglementsOrganismes;
use App\Services\Scolarite\ResultatRattachement;
use App\Support\CandidatureObjectStorage;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Gestes sur les engagements de prise en charge (spec module 4, lot A2 §9.10) : nouvel
 * engagement, rattacher, enregistrer un règlement, relancer, lettre, prolonger, contact.
 * Chaque action n'apparaît qu'avec la permission du geste et délègue au service, qui la
 * revérifie. Après un geste, la fiche se recharge : ses onglets suivent.
 */
final class EngagementActions
{
    public static function nouvelEngagement(): Action
    {
        return Action::make('nouvelEngagement')
            ->label('Nouvel engagement')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => EngagementsPriseEnCharge::peutGerer(auth()->user()))
            ->modalHeading('Nouvel engagement de prise en charge')
            ->modalDescription('La lettre de l\'organisme ouvre l\'accès des auditeurs qu\'on y rattache ; elle ne compte pas comme un paiement. Les auditeurs se rattachent ensuite depuis la fiche de l\'engagement.')
            ->modalSubmitActionLabel('Enregistrer')
            ->form(fn (): array => MessagesValidation::appliquer([
                Forms\Components\Section::make('Lettre')->columns(2)->schema([
                    Forms\Components\TextInput::make('organisme')->label('Organisme')->required()->maxLength(EngagementsPriseEnCharge::ORGANISME_MAX)->columnSpan(2),
                    Forms\Components\TextInput::make('lettre_reference')->label('Référence de la lettre')->required()->maxLength(EngagementsPriseEnCharge::LETTRE_REFERENCE_MAX),
                    Forms\Components\DatePicker::make('lettre_date')->label('Date de la lettre')->required()->native(false)->displayFormat('d/m/Y')
                        ->maxDate(InscriptionAnnuelle::aujourdhui()->endOfDay()->toDateTimeString()),
                    AuditeurActions::champJustificatif('Lettre scannée', 10,
                        'Le stockage n\'a pas répondu : la lettre n\'est pas enregistrée. Réessayez dans quelques instants.', 'lettre_path')
                        ->required()->columnSpan(2),
                ]),
                Forms\Components\Section::make('Engagement')->columns(3)->schema([
                    Forms\Components\Select::make('portee')->label('Portée')->required()->native(false)
                        ->options(EngagementPriseEnCharge::PORTEES)->default(EngagementPriseEnCharge::DROITS_INSCRIPTION),
                    Forms\Components\Select::make('annee_academique')->label('Année académique')->required()->native(false)
                        ->options(fn (): array => EngagementsPriseEnCharge::annees())
                        ->default(fn (): ?string => array_key_first(EngagementsPriseEnCharge::annees())),
                    self::champEcheance('echeance', 'Règlement attendu avant le'),
                ]),
                self::sectionContact(),
            ], self::messages()))
            ->action(function (array $data, Action $action, Component $livewire): void {
                try {
                    // Seule une lettre déposée par ce formulaire, dans cette requête, se rattache à l'engagement.
                    if (! AuditeurActions::depose($data['lettre_path'] ?? null)) {
                        throw new InvalidArgumentException('La lettre jointe est invalide : déposez-la à nouveau.');
                    }
                    $e = app(EngagementsPriseEnCharge::class)->creer($data, auth()->user());
                } catch (InvalidArgumentException $ex) {
                    AuditeurActions::supprimerScanOrphelin($data['lettre_path'] ?? null);
                    Notification::make()->danger()->title('Engagement non enregistré')->body($ex->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Engagement enregistré')->body('Rattachez maintenant les auditeurs couverts.')->send();
                $livewire->redirect(EngagementPriseEnChargeResource::getUrl('view', ['record' => $e]));
            });
    }

    public static function rattacher(): Action
    {
        return Action::make('rattacher')
            ->label('Rattacher des auditeurs')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => EngagementsPriseEnCharge::peutGerer(auth()->user()))
            ->modalHeading('Rattacher des auditeurs')
            ->modalDescription(fn (EngagementPriseEnCharge $record): string => 'Auditeurs inscrits en '.$record->annee_academique.'. Chacun reçoit son accès à eCampus jusqu\'au '
                .$record->echeance->format('d/m/Y').', et en est prévenu par SMS et par e-mail. Chaque auditeur est traité séparément : un refus ne bloque pas les autres.')
            ->modalSubmitActionLabel('Rattacher')
            ->form([
                Forms\Components\Select::make('auditeurs')->label('Auditeurs (matricule ou nom)')->multiple()->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search, EngagementPriseEnCharge $record): array => self::rechercherAuditeurs($record, $search))
                    ->getOptionLabelsUsing(fn (array $values): array => self::libellesAuditeurs($values)),
            ])
            ->action(function (EngagementPriseEnCharge $record, array $data, Component $livewire): void {
                $resultat = app(EngagementsPriseEnCharge::class)->rattacher($record, (array) ($data['auditeurs'] ?? []), auth()->user());
                self::notifierRattachement($resultat);
                self::recharger($record, $livewire);
            });
    }

    public static function enregistrerReglement(): Action
    {
        return Action::make('enregistrerReglement')
            ->label('Enregistrer un règlement')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->visible(fn (EngagementPriseEnCharge $record): bool => ReglementsOrganismes::peutEnregistrer(auth()->user())
                && EngagementPriseEnChargeResource::suivi($record)->nombre() > 0)
            ->modalHeading('Enregistrer un règlement de l\'organisme')
            ->modalDescription(fn (EngagementPriseEnCharge $record): string => 'Chaque part est proposée au reste dû dans la portée de l\'engagement ('
                .mb_strtolower($record->libellePortee()).'), et reste modifiable (0 : rien pour cet auditeur). La somme des parts doit égaler le montant. '
                .'Un versement est créé par auditeur, payé par l\'employeur, sous la référence <référence>-<MATRICULE>.')
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm(fn (EngagementPriseEnCharge $record): array => [
                'date_reglement' => InscriptionAnnuelle::aujourdhui()->toDateString(),
                'mode' => 'virement',
                'parts' => ReglementsOrganismes::partsProposees($record),
            ])
            ->form(fn (EngagementPriseEnCharge $record): array => MessagesValidation::appliquer([
                Forms\Components\Section::make('Règlement')->columns(2)->schema([
                    Forms\Components\TextInput::make('montant')->label('Montant reçu (FCFA)')->required()->integer()->minValue(1)
                        ->maxValue(ReglementsOrganismes::MONTANT_MAX)
                        ->helperText('Somme des parts proposées : '.LibelleSituation::fcfa(array_sum(ReglementsOrganismes::partsProposees($record))).'.'),
                    Forms\Components\DatePicker::make('date_reglement')->label('Date du règlement')->required()->native(false)->displayFormat('d/m/Y')
                        ->maxDate(InscriptionAnnuelle::aujourdhui()->endOfDay()->toDateTimeString()),
                    Forms\Components\Select::make('mode')->label('Mode de paiement')->required()->options(Versement::MODES)->native(false),
                    Forms\Components\TextInput::make('reference')->label('Référence')->required()->maxLength(ReglementsOrganismes::REFERENCE_MAX)
                        ->helperText('Chaque versement portera la référence <référence>-<MATRICULE>.'),
                    AuditeurActions::champJustificatif('Avis de virement ou bordereau (facultatif)', 10,
                        'Le stockage n\'a pas répondu : la pièce n\'est pas enregistrée. Réessayez dans quelques instants.', 'piece_path')->columnSpan(2),
                ]),
                Forms\Components\Section::make('Parts par auditeur')->columns(2)->schema(self::champsParts($record)),
                Forms\Components\Checkbox::make('confirmer_depassement')
                    ->label(fn (Forms\Get $get): string => self::libelleDepassement($record, (array) ($get('parts') ?? [])))
                    ->visible(fn (Forms\Get $get): bool => ReglementsOrganismes::depassements($record, (array) ($get('parts') ?? [])) !== [])
                    ->accepted(),
            ], self::messages()))
            ->action(function (EngagementPriseEnCharge $record, array $data, Action $action, Component $livewire): void {
                try {
                    if (filled($data['piece_path'] ?? null) && ! AuditeurActions::depose((string) $data['piece_path'])) {
                        throw new InvalidArgumentException('La pièce jointe est invalide : déposez-la à nouveau.');
                    }
                    $resultat = app(ReglementsOrganismes::class)->enregistrer($record, $data, auth()->user());
                } catch (InvalidArgumentException $e) {
                    AuditeurActions::supprimerScanOrphelin($data['piece_path'] ?? null);
                    Notification::make()->danger()->title('Règlement non enregistré')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Règlement enregistré')
                    ->body($resultat->versements.' versement(s) créé(s), payé(s) par l\'employeur.'
                        .($resultat->prevenus > 0 ? ' '.$resultat->prevenus.' auditeur(s) prévenu(s) de leurs accès.' : '')
                        .($resultat->echecs > 0 ? ' '.$resultat->echecs.' message(s) des accès non parti(s) : renvoyez-les depuis la fiche de l\'auditeur.' : ''))
                    ->send();
                self::recharger($record, $livewire);
            });
    }

    public static function relancer(): Action
    {
        return Action::make('relancer')
            ->label('Relancer l\'organisme')
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->visible(fn (EngagementPriseEnCharge $record): bool => EngagementsPriseEnCharge::peutGerer(auth()->user())
                && filled($record->contact_email) && EngagementPriseEnChargeResource::suivi($record)->reste > 0)
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-envelope')
            ->modalHeading('Relancer l\'organisme par e-mail')
            ->modalDescription(fn (EngagementPriseEnCharge $record): string => 'Un e-mail part à '.$record->contact_email.' avec les auditeurs qui ont un reste ('
                .LibelleSituation::fcfa(EngagementPriseEnChargeResource::suivi($record)->reste).' au total). '
                .($record->relances > 0
                    ? 'Dernière relance le '.$record->derniere_relance_le?->timezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y').' ('.$record->relances.' en tout).'
                    : 'Aucune relance envoyée jusqu\'ici.'))
            ->modalSubmitActionLabel('Envoyer la relance')
            ->action(function (EngagementPriseEnCharge $record, Action $action, Component $livewire): void {
                try {
                    app(EngagementsPriseEnCharge::class)->relancer($record, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Relance non envoyée')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Relance envoyée')->body('L\'e-mail part par la file des envois, au rythme permis par la messagerie.')->send();
                self::recharger($record, $livewire);
            });
    }

    public static function lettre(): Action
    {
        return Action::make('lettre')
            ->label('Lettre')
            ->icon('heroicon-o-paper-clip')
            ->color('gray')
            // Seuls les chemins du dossier des justificatifs sont signés (même bucket que les pièces des candidats).
            ->visible(fn (EngagementPriseEnCharge $record): bool => (bool) auth()->user()?->can('view_auditeur')
                && Versement::cheminJustificatifValide($record->lettre_path))
            ->action(function (EngagementPriseEnCharge $record) {
                abort_unless(auth()->user()?->can('view_auditeur') && Versement::cheminJustificatifValide($record->lettre_path), 403);
                activity('scolarite')->causedBy(auth()->user())->performedOn($record)->event('engagement_lettre_consultee')->log('Lettre de prise en charge consultée');

                return self::lienSigne((string) $record->lettre_path, 'lettre-prise-en-charge');
            });
    }

    public static function prolonger(): Action
    {
        return Action::make('prolonger')
            ->label('Prolonger')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (): bool => EngagementsPriseEnCharge::peutProlonger(auth()->user()))
            ->modalHeading('Prolonger l\'engagement')
            ->modalDescription(fn (EngagementPriseEnCharge $record): string => 'Échéance actuelle : '.$record->echeance->format('d/m/Y')
                .'. La nouvelle échéance s\'applique à l\'engagement et à l\'accès de tous ses auditeurs encore couverts sans avoir soldé leurs droits.')
            ->modalSubmitActionLabel('Prolonger')
            ->form(MessagesValidation::appliquer([
                self::champEcheance('echeance', 'Nouvelle échéance'),
                Forms\Components\Textarea::make('motif')->label('Motif')->required()
                    ->minLength(DeblocagesAcces::MOTIF_MIN)->maxLength(DeblocagesAcces::MOTIF_MAX)->rows(3),
            ], ['min' => 'Le motif compte au moins '.DeblocagesAcces::MOTIF_MIN.' caractères.'] + self::messages()))
            ->action(function (EngagementPriseEnCharge $record, array $data, Action $action, Component $livewire): void {
                try {
                    $n = app(EngagementsPriseEnCharge::class)->prolonger($record, $data['echeance'] ?? null, $data['motif'] ?? null, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Engagement non prolongé')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Engagement prolongé')->body($n.' accès anticipé(s) reporté(s) à la nouvelle échéance.')->send();
                self::recharger($record, $livewire);
            });
    }

    public static function modifierContact(): Action
    {
        return Action::make('modifierContact')
            ->label('Modifier le contact')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => EngagementsPriseEnCharge::peutGerer(auth()->user()))
            ->modalHeading('Contact pour le règlement')
            ->modalSubmitActionLabel('Enregistrer')
            ->fillForm(fn (EngagementPriseEnCharge $record): array => $record->only(['contact_nom', 'contact_email', 'contact_telephone', 'notes']))
            ->form(MessagesValidation::appliquer([self::sectionContact()], self::messages()))
            ->action(function (EngagementPriseEnCharge $record, array $data, Action $action, Component $livewire): void {
                try {
                    app(EngagementsPriseEnCharge::class)->modifierContact($record, $data, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('Contact non modifié')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->title('Contact modifié')->send();
                self::recharger($record, $livewire);
            });
    }

    /** Lien signé 30 minutes vers une pièce du dossier des justificatifs. */
    public static function lienSigne(string $chemin, string $nom)
    {
        $extension = pathinfo($chemin, PATHINFO_EXTENSION);

        return redirect()->away(Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl(
            $chemin,
            now()->addMinutes(30),
            ['ResponseContentDisposition' => 'inline; filename="'.$nom.'.'.$extension.'"'],
        ));
    }

    /**
     * Auditeurs dont l'inscription courante est de l'année de l'engagement, par matricule ou nom.
     *
     * @return array<string, string> uuid => libellé
     */
    private static function rechercherAuditeurs(EngagementPriseEnCharge $e, string $recherche): array
    {
        $motif = '%'.addcslashes(trim($recherche), '%_\\').'%';

        return Auditeur::query()
            ->whereHas('inscriptionCourante', fn (Builder $q) => $q->where('annee_academique', $e->annee_academique))
            ->whereHas('candidature', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('matricule', 'ilike', $motif)
                ->orWhere('nom', 'ilike', $motif)->orWhere('prenom', 'ilike', $motif)))
            ->with('candidature:id,nom,prenom,matricule')
            ->limit(50)->get()
            ->mapWithKeys(fn (Auditeur $a): array => [(string) $a->uuid => self::libelleAuditeur($a)])
            ->all();
    }

    /**
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    private static function libellesAuditeurs(array $uuids): array
    {
        return Auditeur::query()->whereIn('uuid', $uuids)->with('candidature:id,nom,prenom,matricule')->get()
            ->mapWithKeys(fn (Auditeur $a): array => [(string) $a->uuid => self::libelleAuditeur($a)])
            ->all();
    }

    private static function libelleAuditeur(Auditeur $a): string
    {
        return $a->candidature?->matricule.' — '.trim(mb_strtoupper((string) $a->candidature?->nom).' '.$a->candidature?->prenom);
    }

    /** @return list<Forms\Components\TextInput> une part par auditeur couvert, proposée au reste */
    private static function champsParts(EngagementPriseEnCharge $e): array
    {
        $champs = [];
        foreach (ReglementsOrganismes::couverts($e) as $uuid => $l) {
            $champs[] = Forms\Components\TextInput::make('parts.'.$uuid)
                ->label($l['matricule'].' — '.$l['nom'])
                ->helperText('Reste dû : '.LibelleSituation::fcfa($l['reste']).' sur '.LibelleSituation::fcfa($l['couvert']).' couverts.')
                ->required()->integer()->minValue(0)->maxValue(RegistreVersements::MONTANT_MAX)->suffix('FCFA')->live(onBlur: true);
        }

        return $champs;
    }

    /** @param  array<string, mixed>  $parts */
    private static function libelleDepassement(EngagementPriseEnCharge $e, array $parts): string
    {
        $excedents = array_map(fn (string $m, int $x): string => $m.' : '.RecusVersements::fcfa($x).' de plus',
            array_keys($d = ReglementsOrganismes::depassements($e, $parts)), $d);

        return 'Je confirme : des parts dépassent le reste couvert par l\'engagement ('.implode(' ; ', $excedents).'). '
            .'L\'excédent ira sur le reste de l\'année, puis restera en avance réservée, en attente de la décision du responsable.';
    }

    private static function champEcheance(string $nom, string $libelle): Forms\Components\DatePicker
    {
        return Forms\Components\DatePicker::make($nom)->label($libelle)->required()->native(false)->displayFormat('d/m/Y')
            ->minDate(InscriptionAnnuelle::aujourdhui()->addDay()->toDateString())
            // Fin de journée : le sélecteur renvoie une heure (cf. la date d'un versement).
            ->maxDate(fn (): string => EngagementsPriseEnCharge::echeanceMax()->endOfDay()->toDateTimeString())
            ->helperText(EngagementsPriseEnCharge::JOURS_MAX.' jours au plus.');
    }

    private static function sectionContact(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Contact pour le règlement')->columns(3)->schema([
            Forms\Components\TextInput::make('contact_nom')->label('Nom ou service')->maxLength(EngagementsPriseEnCharge::CONTACT_NOM_MAX),
            Forms\Components\TextInput::make('contact_email')->label('E-mail')->email()->maxLength(255)
                ->helperText('Nécessaire pour relancer l\'organisme.'),
            Forms\Components\TextInput::make('contact_telephone')->label('Téléphone')->tel()->maxLength(EngagementsPriseEnCharge::CONTACT_TELEPHONE_MAX),
            Forms\Components\Textarea::make('notes')->label('Notes')->maxLength(EngagementsPriseEnCharge::NOTES_MAX)->rows(2)->columnSpan(3),
        ]);
    }

    private static function notifierRattachement(ResultatRattachement $r): void
    {
        $refus = implode(' ; ', array_map(fn (string $m, string $motif): string => $m.' : '.$motif, array_keys($r->refus), $r->refus));
        $notification = $r->rattaches === []
            ? Notification::make()->danger()->title('Aucun auditeur rattaché')
            : Notification::make()->success()->title(count($r->rattaches).' auditeur(s) rattaché(s)');
        $corps = [];
        if ($r->prevenus > 0) {
            $corps[] = $r->prevenus.' prévenu(s) de leurs accès par SMS et par e-mail.';
        }
        if ($r->echecs > 0) {
            $corps[] = $r->echecs.' message(s) des accès non parti(s) : utilisez « Renvoyer le SMS et l\'e-mail » sur leur fiche.';
        }
        if ($refus !== '') {
            $corps[] = 'Refusés — '.$refus;
        }
        $notification->body(implode(' ', $corps))->persistent($r->refus !== [] || $r->echecs > 0)->send();
    }

    /** Recharge la fiche : en-tête, totaux et onglets. */
    private static function recharger(EngagementPriseEnCharge $record, Component $livewire): void
    {
        EngagementPriseEnChargeResource::oublier($record);
        $livewire->redirect(EngagementPriseEnChargeResource::getUrl('view', ['record' => $record]));
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        return [
            'after_or_equal' => 'L\'échéance doit être postérieure à aujourd\'hui.',
            'before_or_equal' => 'Date hors des bornes permises.',
            'uploaded' => 'Le fichier n\'a pas pu être envoyé : 10 Mo au plus.',
            'accepted' => 'Cochez la case pour confirmer le dépassement.',
        ];
    }
}
