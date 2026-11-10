<?php

declare(strict_types=1);

namespace App\Filament\Resources\MessageResource\Pages;

use App\Filament\Resources\MessageResource;
use App\Models\EnvoiMessage;
use App\Models\Message;
use App\Models\User;
use App\Services\Messagerie\Messagerie;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateMessage extends CreateRecord
{
    protected static string $resource = MessageResource::class;

    protected static ?string $title = 'Nouveau message';

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();
        try {
            return app(Messagerie::class)->envoyer(
                $user, array_map('intval', (array) $data['destinataires']), (string) $data['objet'], (string) $data['corps'],
                priorite: (string) ($data['priorite'] ?? Message::NORMALE),
                canaux: ($data['copie_courriel'] ?? false) ? [EnvoiMessage::EMAIL] : [],
                pieces: MessageResource::piecesDuFormulaire($data),
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Message non envoyé')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Message envoyé';
    }

    protected function getRedirectUrl(): string
    {
        return MessageResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Envoyer')->icon('heroicon-o-paper-airplane');
    }
}
