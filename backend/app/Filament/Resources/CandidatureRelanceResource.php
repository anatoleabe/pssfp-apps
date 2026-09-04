<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CandidatureRelanceResource\Pages;
use App\Models\CandidatureRelance;
use Filament\Forms\Components\DatePicker;
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

                Tables\Columns\TextColumn::make('expediteur')
                    ->label('Expéditeur')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->description(fn (CandidatureRelance $record): ?string => $record->code_fournisseur !== null
                        ? 'code '.$record->code_fournisseur
                        : null)
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
            ->actions([])
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
