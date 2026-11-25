<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\RecuActions;
use App\Filament\Resources\RecuVersementResource\Pages;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\RecusVersements;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registre des reçus des versements (Scolarité lot 4) : consultation, téléchargement, écriture d'annulation. Dans l'espace
 * Finances depuis le 16/11/2026 (spec module-4-scolarite-espace-financier §6.3) : ouvert à `finances.consulter`, les
 * gestes restent au service financier (`scolarite.recu_emettre`).
 */
#[BelongsToModule('scolarite')]
class RecuVersementResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = RecuVersement::class;

    protected static ?string $modelLabel = 'Reçu';

    protected static ?string $pluralModelLabel = 'Reçus des versements';

    protected static ?string $navigationLabel = 'Reçus des versements';

    protected static ?string $breadcrumb = 'Reçus des versements';

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = GroupeNavigation::FINANCES;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'recus-versements';

    public static function canViewAny(): bool
    {
        return EspaceFinances::peutConsulter(auth()->user());
    }

    /** Versements qui attendent leur reçu de la part de cet agent. */
    public static function getNavigationBadge(): ?string
    {
        $u = auth()->user();
        if ($u === null || ! $u->can(RecusVersements::PERMISSION_EMETTRE)) {
            return null;
        }
        $n = app(RecusVersements::class)->enAttente($u)->count();

        return $n > 0 ? (string) $n : null;
    }

    /** Reprise par la tuile « À traiter » : dit d'où part la liste d'attente (bascule ACMA, spec §5). */
    public static function getNavigationBadgeTooltip(): ?string
    {
        $jour = app(RecusVersements::class)->jourDebutAttente();

        return $jour === null ? 'Versements sans reçu' : 'Versements sans reçu (enregistrés depuis le '.$jour.')';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['annulation', 'remplacant:id,numero,remplace_recu_id', 'remplace:id,numero']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->fontFamily('mono')->searchable()
                    ->description(fn (RecuVersement $r): string => 'Émis le '.$r->emis_at->setTimezone('Africa/Douala')->format('d/m/Y H:i')),
                Tables\Columns\TextColumn::make('auditeur_nom')->label('Auditeur')->searchable(['auditeur_nom', 'matricule'])->wrap()
                    ->description(fn (RecuVersement $r): ?string => $r->matricule),
                Tables\Columns\TextColumn::make('montant')->label('Montant')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => RecusVersements::fcfa($state)),
                Tables\Columns\TextColumn::make('objet')->label('Objet')->wrap()->limit(80),
                Tables\Columns\TextColumn::make('emetteur_nom')->label('Émis par'),
                Tables\Columns\TextColumn::make('etat')->label('État')->badge()
                    ->getStateUsing(fn (RecuVersement $r): string => match (true) {
                        $r->annulation === null => 'En vigueur',
                        $r->remplacant !== null => 'Annulé et remplacé',
                        default => 'Annulé',
                    })
                    ->color(fn (string $state): string => $state === 'En vigueur' ? 'success' : 'gray')
                    ->description(fn (RecuVersement $r): ?string => match (true) {
                        $r->annulation === null => $r->remplace === null ? null : 'Remplace '.$r->remplace->numero,
                        default => $r->annulation->numero.' : '.$r->annulation->motif.($r->remplacant !== null ? ' — remplacé par '.$r->remplacant->numero : ''),
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('annee_numero')->label('Année d\'émission')
                    ->options(fn (): array => RecuVersement::query()->distinct()->orderByDesc('annee_numero')->pluck('annee_numero', 'annee_numero')->all()),
                Tables\Filters\TernaryFilter::make('annule')->label('État')->trueLabel('Annulés')->falseLabel('En vigueur')
                    ->queries(true: fn (Builder $q): Builder => $q->whereHas('annulation'), false: fn (Builder $q): Builder => $q->whereDoesntHave('annulation')),
                Tables\Filters\SelectFilter::make('emis_par')->label('Émis par')
                    ->options(fn (): array => User::query()->whereIn('id', RecuVersement::query()->select('emis_par'))->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('mode')->label('Mode')->options(Versement::MODES),
                Tables\Filters\Filter::make('emission')->label('Période d\'émission')
                    ->form([
                        Forms\Components\DatePicker::make('du')->label('Émis du')->native(false)->displayFormat('d/m/Y'),
                        Forms\Components\DatePicker::make('au')->label('Au')->native(false)->displayFormat('d/m/Y'),
                    ])
                    // Jours de Yaoundé, bornes converties au fuseau de l'application (UTC en production).
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['du'] ?? null), fn (Builder $b) => $b->where('emis_at', '>=', EspaceFinances::debutJour((string) $data['du'])))
                        ->when(filled($data['au'] ?? null), fn (Builder $b) => $b->where('emis_at', '<', EspaceFinances::finJour((string) $data['au'])))),
            ])
            ->actions([Tables\Actions\ActionGroup::make([
                RecuActions::imprimer(fn (RecuVersement $r): RecuVersement => $r),
                Tables\Actions\Action::make('telecharger')->label('Reçu (PDF)')->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (RecuVersement $r): StreamedResponse => RecuActions::reponse($r)),
                Tables\Actions\Action::make('telechargerAnnulation')->label('Écriture d\'annulation (PDF)')->icon('heroicon-o-document-minus')
                    ->visible(fn (RecuVersement $r): bool => $r->annulation !== null)
                    ->action(function (RecuVersement $r): StreamedResponse {
                        $pdf = app(RecusVersements::class)->pdfAnnulation($r->annulation);

                        return response()->streamDownload(function () use ($pdf): void {
                            echo $pdf;
                        }, mb_strtolower($r->annulation->numero).'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
                RecuActions::renvoyer(fn (RecuVersement $r): ?RecuVersement => $r->annulation === null ? $r : null),
                RecuActions::reemettre(fn (RecuVersement $r): ?RecuVersement => $r->annulation === null ? $r : null, 'reemettre'),
            ])->label('Actions')->icon('heroicon-o-ellipsis-vertical')]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRecusVersements::route('/')];
    }
}
