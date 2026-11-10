<?php

declare(strict_types=1);

namespace App\Filament\Resources\MessageResource\Pages;

use App\Filament\Resources\MessageResource;
use App\Models\EnvoiMessage;
use App\Models\Message;
use App\Models\PieceMessage;
use App\Models\User;
use App\Services\Messagerie\Messagerie;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Lecture d'un fil : ouvrir le marque lu ; l'expéditeur voit qui a lu. */
class ViewMessage extends ViewRecord
{
    protected static string $resource = MessageResource::class;

    protected static string $view = 'filament.messagerie.fil';

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(Messagerie::class)->lireFil($this->message(), $this->utilisateur());
    }

    public function getTitle(): string
    {
        return $this->message()->objet;
    }

    /** Messages du fil visibles par l'utilisateur. */
    public function getFil(): Collection
    {
        return app(Messagerie::class)->fil($this->message(), $this->utilisateur());
    }

    public function telecharger(int $piece): StreamedResponse
    {
        $p = PieceMessage::query()->with('message')->findOrFail($piece);
        abort_unless($p->message->fil === $this->message()->fil, 404);

        return app(Messagerie::class)->telecharger($p, $this->utilisateur());
    }

    protected function getHeaderActions(): array
    {
        $destinataire = fn (): ?\App\Models\MessageDestinataire => $this->message()->destinataires()->where('user_id', $this->utilisateur()->id)->first();

        return [
            Actions\Action::make('repondre')->label('Répondre')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => ! $this->message()->estDuSysteme())
                ->modalHeading('Répondre à tous')->modalSubmitActionLabel('Envoyer')
                ->form([
                    Forms\Components\Textarea::make('corps')->label('Réponse')->required()->rows(8)->maxLength(Message::CORPS_MAX),
                    ...MessageResource::champsEnvoi(),
                ])
                ->action(function (array $data): void {
                    try {
                        $reponse = app(Messagerie::class)->repondre($this->utilisateur(), $this->message(), (string) $data['corps'],
                            ($data['copie_courriel'] ?? false) ? [EnvoiMessage::EMAIL] : [], MessageResource::piecesDuFormulaire($data));
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Réponse non envoyée')->body($e->getMessage())->persistent()->send();

                        throw new Halt;
                    }
                    Notification::make()->success()->title('Réponse envoyée')->send();
                    $this->redirect(MessageResource::getUrl('view', ['record' => $reponse]));
                }),
            Actions\Action::make('archiver')->label('Archiver')->icon('heroicon-o-archive-box')->color('gray')
                ->visible(fn (): bool => $destinataire()?->archive_le === null && $destinataire() !== null)
                ->action(function (): void {
                    app(Messagerie::class)->archiver($this->message(), $this->utilisateur());
                    Notification::make()->success()->title('Message archivé')->send();
                    $this->redirect(MessageResource::getUrl('index'));
                }),
            Actions\Action::make('desarchiver')->label('Remettre dans les reçus')->icon('heroicon-o-inbox-arrow-down')->color('gray')
                ->visible(fn (): bool => $destinataire()?->archive_le !== null)
                ->action(function (): void {
                    app(Messagerie::class)->archiver($this->message(), $this->utilisateur(), false);
                    Notification::make()->success()->title('Message remis dans les reçus')->send();
                }),
        ];
    }

    private function message(): Message
    {
        /** @var Message $m */
        $m = $this->getRecord();

        return $m;
    }

    private function utilisateur(): User
    {
        /** @var User $u */
        $u = auth()->user();

        return $u;
    }
}
