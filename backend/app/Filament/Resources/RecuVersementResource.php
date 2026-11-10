<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\RecuActions;
use App\Filament\Resources\RecuVersementResource\Pages;
use App\Models\RecuVersement;
use App\Models\User;
use App\Services\Scolarite\RecusVersements;
use App\Support\BelongsToModule;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Registre des reçus des versements (Scolarité lot 4) : consultation, téléchargement, écriture d'annulation. */
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

    protected static ?string $navigationGroup = GroupeNavigation::SCOLARITE;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'recus-versements';

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

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Versements sans reçu';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('annulation'))
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
                    ->getStateUsing(fn (RecuVersement $r): string => $r->annulation === null ? 'En vigueur' : 'Annulé')
                    ->color(fn (string $state): string => $state === 'En vigueur' ? 'success' : 'gray')
                    ->description(fn (RecuVersement $r): ?string => $r->annulation === null ? null : $r->annulation->numero.' : '.$r->annulation->motif),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('annee_numero')->label('Année d\'émission')
                    ->options(fn (): array => RecuVersement::query()->distinct()->orderByDesc('annee_numero')->pluck('annee_numero', 'annee_numero')->all()),
                Tables\Filters\TernaryFilter::make('annule')->label('État')->trueLabel('Annulés')->falseLabel('En vigueur')
                    ->queries(true: fn (Builder $q): Builder => $q->whereHas('annulation'), false: fn (Builder $q): Builder => $q->whereDoesntHave('annulation')),
                Tables\Filters\SelectFilter::make('emis_par')->label('Émis par')
                    ->options(fn (): array => User::query()->whereIn('id', RecuVersement::query()->select('emis_par'))->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->actions([Tables\Actions\ActionGroup::make([
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
                Tables\Actions\Action::make('annuler')->label('Annuler le reçu')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (RecuVersement $r): bool => RecuActions::peutEmettre() && $r->annulation === null)
                    ->modalDescription('Le reçu n\'est pas modifié : une écriture d\'annulation numérotée est passée à votre nom. Un nouveau reçu peut ensuite être émis depuis la fiche de l\'auditeur.')
                    ->form(RecuActions::formulaireMotif())
                    ->action(function (RecuVersement $r, array $data): void {
                        $a = RecuActions::executer(fn () => app(RecusVersements::class)->annuler($r, (string) $data['motif'], auth()->user()));
                        Notification::make()->success()->title('Écriture '.$a->numero.' passée')->send();
                    }),
            ])->label('Actions')->icon('heroicon-o-ellipsis-vertical')]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRecusVersements::route('/')];
    }
}
