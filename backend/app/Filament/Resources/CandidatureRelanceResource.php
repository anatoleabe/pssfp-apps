<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CandidatureRelanceResource\Pages;
use App\Models\CandidatureRelance;
use App\Services\Sms\EchoSmsCodes;
use App\Services\Sms\QueriesMessageStatus;
use App\Services\Sms\SmsDeliveryStatuses;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Sms\TechSoftCodes;
use App\Support\SecretRedactor;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Journal des envois SMS et e-mail adressés aux candidats.
 *
 * Écran strictement en lecture : un journal qu'on peut modifier ne prouve
 * plus rien. Ni création, ni édition, ni suppression — les lignes naissent
 * uniquement des envois réels.
 *
 * Répond à trois questions opérationnelles :
 * - un candidat a-t-il bien été joint, et quand ;
 * - un envoi a-t-il échoué, et pour quel motif exact ;
 * - sous quelle identité d'expéditeur le message est parti (Sender ID pour
 *   un SMS, adresse d'expédition pour un e-mail).
 *
 * Réservé à admin et super_admin, comme l'envoi lui-même : le journal
 * contient le texte intégral des messages adressés aux candidats.
 */
class CandidatureRelanceResource extends Resource
{
    protected static ?string $model = CandidatureRelance::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Module 5 — Admissions';

    protected static ?string $navigationLabel = 'Journal des envois';

    protected static ?string $modelLabel = 'envoi';

    protected static ?string $pluralModelLabel = 'journal des envois';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('candidature.notify');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Compteur d'échecs directement dans la navigation, pour qu'ils se voient. */
    public static function getNavigationBadge(): ?string
    {
        $echecs = static::getModel()::query()
            ->where('statut', CandidatureRelance::STATUT_ECHEC)
            ->count();

        return $echecs > 0 ? (string) $echecs : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager loading : sans lui, la colonne candidat déclencherait une
        // requête par ligne affichée.
        return parent::getEloquentQuery()->with(['candidature', 'auteur']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sent_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Envoyé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('candidature.numero_dossier')
                    ->label('Dossier')
                    ->searchable()
                    ->copyable()
                    ->url(fn (CandidatureRelance $record): ?string => $record->candidature
                        ? CandidatureResource::getUrl('view', ['record' => $record->candidature])
                        : null),

                Tables\Columns\TextColumn::make('candidature.nom')
                    ->label('Candidat')
                    ->formatStateUsing(fn (?string $state, CandidatureRelance $record): string => trim(
                        ($record->candidature?->prenom ?? '').' '.($state ?? '')
                    ) ?: '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('candidature', fn (Builder $q) => $q
                            ->where('nom', 'ilike', "%{$search}%")
                            ->orWhere('prenom', 'ilike', "%{$search}%"));
                    }),

                Tables\Columns\TextColumn::make('canal')
                    ->label('Canal')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CandidatureRelance::CANAL_SMS ? 'SMS' : 'E-mail')
                    ->color(fn (string $state): string => $state === CandidatureRelance::CANAL_SMS ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('statut')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CandidatureRelance::STATUT_ENVOYE ? 'Envoyé' : 'Échec')
                    ->color(fn (string $state): string => $state === CandidatureRelance::STATUT_ENVOYE ? 'success' : 'danger'),

                // Statut réel chez la passerelle, distinct du statut d'envoi :
                // une passerelle peut accepter un message puis échouer à le
                // livrer. C'est le cas que le journal ne savait pas montrer.
                Tables\Columns\TextColumn::make('statut_livraison')
                    ->label('Livraison')
                    ->badge()
                    ->placeholder('Inconnu')
                    // Table partagée avec le provider : les deux divergeaient,
                    // un même statut portait deux libellés sur le même écran.
                    ->color(fn (?string $state): string => SmsDeliveryStatuses::couleur($state))
                    ->formatStateUsing(fn (?string $state): string => SmsDeliveryStatuses::libelle($state)),

                Tables\Columns\TextColumn::make('cout')
                    ->label('Coût')
                    ->placeholder('—')
                    ->toggleable(),

                // Colonne autonome plutôt qu'une description accrochée à
                // l'expéditeur : celle-ci disparaissait dès que l'expéditeur
                // était nul, c'est-à-dire sur les échecs, quand le code est le
                // plus utile.
                Tables\Columns\TextColumn::make('code_fournisseur')
                    ->label('Code fournisseur')
                    ->placeholder('—')
                    ->formatStateUsing(function (?string $state): string {
                        if ($state === null || $state === '') {
                            return '—';
                        }

                        // Les deux tables sont interrogées : le journal contient
                        // des codes Echo SMS antérieurs à la bascule TechSoft.
                        $libelle = EchoSmsCodes::libelle($state) ?? TechSoftCodes::libelle($state);

                        return ($libelle ?? 'Code inconnu').' ('.$state.')';
                    })
                    // Visible par défaut : c'est la colonne qui explique un
                    // échec, la masquer reviendrait à annuler le gain.
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expediteur')
                    ->label('Expéditeur')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cause')
                    ->label('Motif')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => static::libelleCause($state))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('auteur.name')
                    ->label('Déclenché par')
                    ->placeholder('Automatique')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sujet')
                    ->label('Objet')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('message')
                    ->label('Message')
                    ->limit(60)
                    ->tooltip(fn (CandidatureRelance $record): ?string => $record->message)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('erreur')
                    ->label('Erreur')
                    ->limit(50)
                    ->color('danger')
                    ->tooltip(fn (CandidatureRelance $record): ?string => $record->erreur)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                // Sans ce filtre, la donnee est collectee mais inexploitable :
                // le badge et l'onglet « Echecs » comptent les refus de la
                // passerelle, pas les echecs de livraison — soit precisement
                // le cas que ce lot devait rendre visible.
                Tables\Filters\Filter::make('non_livres')
                    ->label('Envoyés mais non livrés')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('statut', CandidatureRelance::STATUT_ENVOYE)
                        ->whereNotNull('message_uid')
                        ->where(function (Builder $q): Builder {
                            return $q->whereNull('statut_livraison')
                                ->orWhereRaw('LOWER(statut_livraison) <> ?', [SmsDeliveryStatuses::LIVRE]);
                        }))
                    ->toggle(),
                Tables\Filters\SelectFilter::make('canal')
                    ->label('Canal')
                    ->options([
                        CandidatureRelance::CANAL_SMS => 'SMS',
                        CandidatureRelance::CANAL_EMAIL => 'E-mail',
                    ]),

                Tables\Filters\SelectFilter::make('statut')
                    ->label('Statut')
                    ->options([
                        CandidatureRelance::STATUT_ENVOYE => 'Envoyé',
                        CandidatureRelance::STATUT_ECHEC => 'Échec',
                    ]),

                Tables\Filters\SelectFilter::make('cause')
                    ->label('Motif')
                    ->options(fn (): array => static::causesDisponibles()),

                // Options lues en base plutôt que codées en dur : le Sender ID
                // change au fil des campagnes, et un masking expiré doit
                // rester filtrable après son remplacement.
                Tables\Filters\SelectFilter::make('expediteur')
                    ->label('Expéditeur')
                    ->options(fn (): array => static::expediteursConnus()),

                Tables\Filters\Filter::make('periode')
                    ->form([
                        DatePicker::make('du')
                            ->label('Du')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('au')
                            ->label('Au')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['du'] ?? null, fn (Builder $q, $date) => $q->whereDate('sent_at', '>=', $date))
                        ->when($data['au'] ?? null, fn (Builder $q, $date) => $q->whereDate('sent_at', '<=', $date)))
                    ->indicateUsing(function (array $data): ?string {
                        if (($data['du'] ?? null) === null && ($data['au'] ?? null) === null) {
                            return null;
                        }

                        return 'Période : '.($data['du'] ?? '…').' → '.($data['au'] ?? '…');
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('actualiser_statut')
                    ->label('Actualiser le statut')
                    ->icon('heroicon-o-arrow-path')
                    // Masquée sans identifiant : tout l'historique antérieur à
                    // la bascule TechSoft est dans ce cas, et la passerelle
                    // active doit savoir répondre.
                    ->visible(fn (CandidatureRelance $record): bool => $record->message_uid !== null
                        && $record->canal === CandidatureRelance::CANAL_SMS
                        && app(SmsServiceInterface::class) instanceof QueriesMessageStatus)
                    ->action(function (CandidatureRelance $record): void {
                        $passerelle = app(SmsServiceInterface::class);

                        // Re-testé au point d'appel : la garde vit dans
                        // visible(), à distance, et l'élargir un jour
                        // provoquerait un BadMethodCallException en pleine page.
                        if (! $passerelle instanceof QueriesMessageStatus) {
                            return;
                        }

                        try {
                            $statut = $passerelle->statutMessage((string) $record->message_uid);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()
                                ->title('Statut indisponible')
                                ->body(SecretRedactor::redact($e->getMessage()) ?? 'Motif inconnu.')
                                ->send();

                            return;
                        }

                        $avant = $record->statut_livraison;

                        $record->update([
                            'statut_livraison' => $statut->brut,
                            'cout' => $statut->cout ?? $record->cout,
                        ]);

                        // Ce journal sert de preuve et se déclare non
                        // modifiable : la seule écriture qui subsiste doit
                        // laisser une trace de son auteur et de l'état d'avant.
                        activity('envois')
                            ->causedBy(auth()->user())
                            ->performedOn($record)
                            ->withProperties(['avant' => $avant, 'apres' => $statut->brut])
                            ->event('statut_livraison_actualise')
                            ->log('Statut de livraison actualisé depuis la passerelle');

                        Notification::make()->success()
                            ->title('Statut actualisé')->body($statut->libelle)->send();
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Aucun envoi enregistré')
            ->emptyStateDescription(
                'Les SMS et e-mails adressés aux candidats apparaîtront ici, '
                .'avec leur statut et leur expéditeur.'
            );
    }

    /** @return array<string, string> */
    private static function causesDisponibles(): array
    {
        $libelles = [
            CandidatureRelance::CAUSE_MANUELLE => 'Notification manuelle',
            'ready' => 'Relance — dossier complet non soumis',
            'photo_only' => 'Relance — photo manquante',
        ];

        // Une cause apparue en base mais inconnue ici reste filtrable.
        foreach (static::getModel()::query()->distinct()->pluck('cause') as $cause) {
            $libelles[$cause] ??= $cause;
        }

        return $libelles;
    }

    /** @return array<string, string> */
    private static function expediteursConnus(): array
    {
        return static::getModel()::query()
            ->whereNotNull('expediteur')
            ->distinct()
            ->orderBy('expediteur')
            ->pluck('expediteur', 'expediteur')
            ->all();
    }

    private static function libelleCause(string $cause): string
    {
        return match ($cause) {
            CandidatureRelance::CAUSE_MANUELLE => 'Manuelle',
            'ready' => 'Dossier complet',
            'photo_only' => 'Photo manquante',
            default => $cause,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCandidatureRelances::route('/'),
        ];
    }
}
