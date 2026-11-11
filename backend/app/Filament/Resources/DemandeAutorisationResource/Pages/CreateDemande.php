<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemandeAutorisationResource\Pages;

use App\Filament\Resources\DemandeAutorisationResource;
use App\Models\User;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\TypesDemande;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateDemande extends CreateRecord
{
    protected static string $resource = DemandeAutorisationResource::class;

    protected static ?string $title = 'Nouvelle demande';

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();
        $type = (string) $data['type'];
        try {
            return app(Demandes::class)->soumettre($user, $type,
                TypesDemande::aUnObjet($type) ? (int) $data['entite_id'] : null, (string) $data['motif'], $data['objet_libre'] ?? null);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Demande non soumise')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Demande soumise : les décideurs sont prévenus';
    }

    protected function getRedirectUrl(): string
    {
        return DemandeAutorisationResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Soumettre')->icon('heroicon-o-paper-airplane');
    }
}
