<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeanceRequetesResource\Pages;

use App\Filament\Resources\SeanceRequetesResource;
use App\Models\Jury;
use App\Models\User;
use App\Services\Lmd\Requetes;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateSeanceRequetes extends CreateRecord
{
    protected static string $resource = SeanceRequetesResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $auteur */
        $auteur = auth()->user();
        try {
            return app(Requetes::class)->creerSeance(Jury::query()->findOrFail((int) $data['jury_id']), (string) $data['tenue_le'], $auteur);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.jury_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return SeanceRequetesResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
