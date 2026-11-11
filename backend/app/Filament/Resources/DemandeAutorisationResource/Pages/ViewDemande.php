<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemandeAutorisationResource\Pages;

use App\Filament\Resources\DemandeAutorisationResource;
use App\Models\DemandeAutorisation;
use App\Models\User;
use App\Services\Messagerie\Demandes;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
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
                ->modalDescription('L\'approbation finale exécute l\'opération demandée, sous l\'identité du responsable qui l\'a approuvée.')
                ->form([Forms\Components\Textarea::make('commentaire')->label('Commentaire (facultatif)')->maxLength(1000)])
                ->action(fn (array $data) => $this->decider(true, $data['commentaire'] ?? null)),
            Actions\Action::make('rejeter')->label('Rejeter')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (): bool => app(Demandes::class)->peutDecider($this->utilisateur(), $this->demande()))
                ->form([Forms\Components\Textarea::make('commentaire')->label('Motif du rejet')->required()->minLength(Demandes::COMMENTAIRE_MIN)->maxLength(1000)])
                ->action(fn (array $data) => $this->decider(false, $data['commentaire'])),
            Actions\Action::make('retirer')->label('Retirer ma demande')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (): bool => $this->demande()->demandeur_id === $this->utilisateur()->id
                    && $this->demande()->statut === DemandeAutorisation::SOUMISE && ! $this->demande()->decisions()->exists())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->executer(fn () => app(Demandes::class)->retirer($this->utilisateur(), $this->demande()), 'Demande retirée');
                }),
        ];
    }

    private function decider(bool $approuve, ?string $commentaire): void
    {
        $this->executer(fn () => app(Demandes::class)->decider($this->utilisateur(), $this->demande(), $approuve, $commentaire, $this->statutAffiche), null);
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
