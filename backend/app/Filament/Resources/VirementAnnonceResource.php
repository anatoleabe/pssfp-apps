<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Filament\Resources\VirementAnnonceResource\Pages;
use App\Models\Versement;
use App\Models\VirementAnnonce;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\VirementsAnnonces;
use App\Support\BelongsToModule;
use App\Support\CandidatureObjectStorage;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Virements annoncés (spec virements-bordereaux §2) : hors compte jusqu'à leur validation, à la date de paiement,
 * qui crée le versement et émet son reçu.
 */
#[BelongsToModule('scolarite')]
class VirementAnnonceResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = VirementAnnonce::class;

    protected static ?string $modelLabel = 'virement';

    protected static ?string $pluralModelLabel = 'Virements à valider';

    protected static ?string $navigationLabel = 'Virements à valider';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = GroupeNavigation::FINANCES;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'finances/virements';

    public static function canViewAny(): bool
    {
        return VirementsAnnonces::peutAnnoncer(auth()->user()) || EspaceFinances::peutConsulter(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = VirementsAnnonces::enAttente()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Virements en attente de crédit';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['auditeur.candidature:id,nom,prenom,matricule', 'saisiPar:id,name', 'traitePar:id,name', 'versement.recus']))
            ->defaultSort('created_at')
            ->columns([
                Tables\Columns\TextColumn::make('auditeur')->label('Auditeur')->wrap()
                    ->state(fn (VirementAnnonce $r): string => trim(($r->auditeur?->candidature?->nom ?? '').' '.($r->auditeur?->candidature?->prenom ?? '')))
                    ->description(fn (VirementAnnonce $r): ?string => $r->auditeur?->candidature?->matricule),
                Tables\Columns\TextColumn::make('montant')->label('Montant')->formatStateUsing(fn (int $state): string => LibelleSituation::fcfa($state))->alignEnd(),
                Tables\Columns\TextColumn::make('reference')->label('Référence')->fontFamily('mono')->searchable(),
                Tables\Columns\TextColumn::make('date_ordre')->label('Ordre du')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (string $state): string => VirementAnnonce::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        VirementAnnonce::EN_ATTENTE => 'warning', VirementAnnonce::VALIDE => 'success', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('date_paiement')->label('Payé le')->date('d/m/Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('saisiPar.name')->label('Saisi par')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Saisi le')->dateTime('d/m/Y H:i', 'Africa/Douala')->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('voirOrdre')->label('Ordre de virement')->icon('heroicon-o-paper-clip')->color('gray')
                    ->visible(fn (VirementAnnonce $record): bool => Versement::cheminJustificatifValide($record->justificatif_path) && auth()->user()?->can('view_auditeur') === true)
                    ->action(function (VirementAnnonce $record) {
                        abort_unless(Versement::cheminJustificatifValide($record->justificatif_path) && auth()->user()?->can('view_auditeur'), 403);
                        activity('scolarite')->causedBy(auth()->user())->performedOn($record)->event('justificatif_consulte')->log('Ordre de virement consulté');

                        return redirect()->away(Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl((string) $record->justificatif_path, now()->addMinutes(30),
                            ['ResponseContentDisposition' => 'inline; filename="virement.'.pathinfo((string) $record->justificatif_path, PATHINFO_EXTENSION).'"']));
                    }),
                Tables\Actions\Action::make('valider')->label('Valider')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (VirementAnnonce $record): bool => $record->statut === VirementAnnonce::EN_ATTENTE && VirementsAnnonces::peutValider(auth()->user()))
                    ->modalHeading('Valider le virement à sa date de paiement')
                    ->modalDescription(fn (VirementAnnonce $record): string => LibelleSituation::fcfa($record->montant).', référence '.$record->reference
                        .', ordre du '.$record->date_ordre->format('d/m/Y').'. Le versement est créé à la date de paiement, son reçu émis à votre nom, et l\'auditeur prévenu.')
                    ->form([
                        Forms\Components\DatePicker::make('date_paiement')->label('Date de paiement (crédit sur le compte)')->required()->native(false)->displayFormat('d/m/Y')
                            ->minDate(fn (VirementAnnonce $record): string => $record->date_ordre->toDateString())
                            ->maxDate(now('Africa/Douala')->endOfDay()->toDateTimeString()),
                    ])
                    ->action(function (VirementAnnonce $record, array $data, Tables\Actions\Action $action): void {
                        try {
                            $resultat = app(RegistreVersements::class)->encaisserVirementAnnonce($record, (string) $data['date_paiement'], auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Virement non validé')->body($e->getMessage())->send();
                            $action->halt();

                            return;
                        }
                        AuditeurActions::avecImpression(Notification::make()->success()->title('Virement validé, reçu '.$resultat->recu->numero.' émis')
                            ->body('L\'auditeur peut le télécharger dans son espace.'), $resultat->recu)->send();
                    }),
                Tables\Actions\Action::make('abandonner')->label('Abandonner')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (VirementAnnonce $record): bool => $record->statut === VirementAnnonce::EN_ATTENTE && VirementsAnnonces::peutAnnoncer(auth()->user()))
                    ->modalDescription('Le virement n\'est jamais arrivé : il ne comptera pas, et rien n\'est supprimé.')
                    ->form([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(10)->maxLength(500)])
                    ->action(function (VirementAnnonce $record, array $data, Tables\Actions\Action $action): void {
                        try {
                            app(VirementsAnnonces::class)->abandonner($record, (string) $data['motif'], auth()->user());
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Abandon refusé')->body($e->getMessage())->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->success()->title('Virement abandonné')->send();
                    }),
            ])
            ->emptyStateHeading('Aucun virement')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVirementsAnnonces::route('/')];
    }
}
