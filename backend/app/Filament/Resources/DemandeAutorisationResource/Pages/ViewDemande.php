<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemandeAutorisationResource\Pages;

use App\Filament\Resources\DemandeAutorisationResource;
use App\Models\DemandeAutorisation;
use App\Models\User;
use App\Models\Versement;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use App\Services\Scolarite\CorrectionsVersements;
use App\Services\Scolarite\DemandeMoratoire;
use App\Services\Scolarite\LibelleSituation;
use App\Support\CandidatureObjectStorage;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ViewDemande extends ViewRecord
{
    protected static string $resource = DemandeAutorisationResource::class;

    /** Statut affiché à l'ouverture de la fiche : une décision sur une fiche périmée est refusée (Livewire relit l'enregistrement). */
    public ?string $statutAffiche = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->statutAffiche = $this->demande()->statut;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approuver')->label(fn (): string => $this->demande()->statut === DemandeAutorisation::VISA_ATTENDU ? 'Viser' : 'Approuver')
                ->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (): bool => app(Demandes::class)->peutDecider($this->utilisateur(), $this->demande()))
                ->modalDescription(fn (): string => match ($this->demande()->type) {
                    TypesDemande::ACCORDER_MORATOIRE => DemandeMoratoire::avertissement($this->demande()->parametres),
                    TypesDemande::CORRIGER_VERSEMENT => 'L\'approbation exécute aussitôt la correction : le versement est annulé avec son reçu, puis remplacé au nom du demandeur. Rien n\'est supprimé.',
                    default => 'L\'approbation finale exécute l\'opération demandée, sous l\'identité du responsable qui l\'a approuvée.',
                })
                ->form(fn (): array => [
                    Forms\Components\Placeholder::make('prevision')->label('Effet prévu')
                        ->visible(fn (): bool => $this->versementCorrige() !== null)
                        ->content(fn (): string => $this->prevision()),
                    Forms\Components\Checkbox::make(CorrectionsVersements::OPTION_ATTESTATION)->label(CorrectionsVersements::ATTESTATION)
                        ->helperText('Le versement était confirmé par la scolarité et change de montant ou d\'auditeur : consultez le scan d\'origine (« Voir le scan d\'origine »).')
                        ->visible(fn (): bool => $this->exigences()[CorrectionsVersements::OPTION_ATTESTATION])->accepted(),
                    Forms\Components\Checkbox::make(CorrectionsVersements::OPTION_FERMETURE)->label(CorrectionsVersements::FERMETURE)
                        ->visible(fn (): bool => $this->exigences()[CorrectionsVersements::OPTION_FERMETURE])->accepted(),
                    Forms\Components\Textarea::make('commentaire')->label('Commentaire (facultatif)')->maxLength(1000),
                ])
                ->action(fn (array $data) => $this->decider(true, $data['commentaire'] ?? null,
                    array_intersect_key($data, array_flip([CorrectionsVersements::OPTION_ATTESTATION, CorrectionsVersements::OPTION_FERMETURE])))),
            Actions\Action::make('rejeter')->label('Rejeter')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (): bool => app(Demandes::class)->peutDecider($this->utilisateur(), $this->demande()))
                ->form([Forms\Components\Textarea::make('commentaire')->label('Motif du rejet')->required()->minLength(Demandes::COMMENTAIRE_MIN)->maxLength(1000)])
                ->action(fn (array $data) => $this->decider(false, $data['commentaire'])),
            // Pièce jointe par le demandeur (lot A3 : demande écrite de l'auditeur), pour qui voit la demande et les auditeurs.
            Actions\Action::make('voirPiece')->label('Voir la pièce jointe')->icon('heroicon-o-paper-clip')->color('gray')
                ->visible(fn (): bool => $this->piece() !== null && $this->utilisateur()->can('view_auditeur'))
                ->action(function () {
                    $chemin = $this->piece();
                    abort_unless($chemin !== null && $this->utilisateur()->can('view_auditeur'), 403);
                    activity('scolarite')->causedBy($this->utilisateur())->performedOn($this->demande())
                        ->event('demande_piece_consultee')->withProperties(['demande' => $this->demande()->numero])->log('Pièce d\'une demande consultée');

                    return redirect()->away(Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl($chemin, now()->addMinutes(30),
                        ['ResponseContentDisposition' => 'inline; filename="piece-'.$this->demande()->numero.'.'.pathinfo($chemin, PATHINFO_EXTENSION).'"']));
                }),
            // Correction d'un versement confirmé : le scan du bordereau vérifié par la scolarité, sur lequel le décideur atteste.
            Actions\Action::make('voirScanOrigine')->label('Voir le scan d\'origine')->icon('heroicon-o-document-magnifying-glass')->color('gray')
                ->visible(fn (): bool => $this->scanOrigine() !== null && $this->utilisateur()->can('view_auditeur'))
                ->action(function () {
                    $chemin = $this->scanOrigine();
                    abort_unless($chemin !== null && $this->utilisateur()->can('view_auditeur'), 403);
                    activity('scolarite')->causedBy($this->utilisateur())->performedOn($this->demande())
                        ->event('demande_piece_consultee')->withProperties(['demande' => $this->demande()->numero, 'piece' => 'scan_origine'])->log('Pièce d\'une demande consultée');

                    return redirect()->away(Storage::disk(CandidatureObjectStorage::DISK)->temporaryUrl($chemin, now()->addMinutes(30),
                        ['ResponseContentDisposition' => 'inline; filename="scan-'.$this->demande()->numero.'.'.pathinfo($chemin, PATHINFO_EXTENSION).'"']));
                }),
            Actions\Action::make('retirer')->label('Retirer ma demande')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (): bool => $this->demande()->demandeur_id === $this->utilisateur()->id
                    && $this->demande()->statut === DemandeAutorisation::SOUMISE && ! $this->demande()->decisions()->exists())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->executer(fn () => app(Demandes::class)->retirer($this->utilisateur(), $this->demande()), 'Demande retirée');
                }),
        ];
    }

    /** @param  array<string, mixed>  $options */
    private function decider(bool $approuve, ?string $commentaire, array $options = []): void
    {
        $this->executer(fn () => app(Demandes::class)->decider($this->utilisateur(), $this->demande(), $approuve, $commentaire, $this->statutAffiche, $options), null);
    }

    /** Versement visé par une demande de correction encore en attente, s'il peut toujours être corrigé. */
    private function versementCorrige(): ?Versement
    {
        $d = $this->demande();
        if ($d->type !== TypesDemande::CORRIGER_VERSEMENT || ! $d->enAttente() || $d->entite_id === null) {
            return null;
        }
        $v = Versement::query()->find($d->entite_id);

        return $v !== null && CorrectionsVersements::raisonRefus($v) === null ? $v : null;
    }

    /** @return array{attestation: bool, fermeture: bool} */
    private function exigences(): array
    {
        $v = $this->versementCorrige();

        $aucune = [CorrectionsVersements::OPTION_ATTESTATION => false, CorrectionsVersements::OPTION_FERMETURE => false];
        try {
            return $v === null ? $aucune : app(CorrectionsVersements::class)->exigences($v, $this->demande()->parametres ?? []);
        } catch (InvalidArgumentException) {
            // La correction serait refusée : la prévision en donne la raison, l'approbation la rappellera.
            return $aucune;
        }
    }

    /** Prévision lisible (R-C7) : pour chaque auditeur, accès eCampus, versé et reste, avant puis après. */
    private function prevision(): string
    {
        $v = $this->versementCorrige();
        if ($v === null) {
            return '—';
        }
        $acces = fn (bool $o): string => $o ? 'ouvert' : 'fermé';
        try {
            $prevision = app(CorrectionsVersements::class)->prevoir($v, $this->demande()->parametres ?? []);
        } catch (InvalidArgumentException $e) {
            return 'Correction impossible en l\'état : '.$e->getMessage();
        }

        return collect($prevision)
            ->map(fn (array $l): string => $l['matricule'].' : accès eCampus '.$acces($l['acces_avant']).' → '.$acces($l['acces_apres'])
                .' ; versé '.LibelleSituation::fcfa($l['verse_avant']).' → '.LibelleSituation::fcfa($l['verse_apres'])
                .' (dont à confirmer '.LibelleSituation::fcfa($l['a_confirmer_apres']).') ; reste '.LibelleSituation::fcfa($l['reste_avant']).' → '.LibelleSituation::fcfa($l['reste_apres']))
            ->implode(' | ');
    }

    private function scanOrigine(): ?string
    {
        $d = $this->demande();
        if ($d->type !== TypesDemande::CORRIGER_VERSEMENT || $d->entite_id === null) {
            return null;
        }
        $chemin = Versement::query()->find($d->entite_id)?->confirmation?->scan();

        return $chemin !== null && Versement::cheminJustificatifValide($chemin) ? $chemin : null;
    }

    private function executer(callable $operation, ?string $titre): void
    {
        try {
            $resultat = $operation();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
        $this->getRecord()->refresh();
        $this->statutAffiche = $this->demande()->statut;
        $echec = $this->demande()->statut === DemandeAutorisation::ECHEC;
        Notification::make()->{$echec ? 'warning' : 'success'}()->title($titre ?? (string) $resultat)->send();
    }

    private function piece(): ?string
    {
        return TypesDemande::piece($this->demande()->type, $this->demande()->parametres);
    }

    private function demande(): DemandeAutorisation
    {
        /** @var DemandeAutorisation $d */
        $d = $this->getRecord();

        return $d;
    }

    private function utilisateur(): User
    {
        /** @var User $u */
        $u = auth()->user();

        return $u;
    }
}
