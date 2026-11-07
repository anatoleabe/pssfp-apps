<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeanceResource\Pages;

use App\Filament\Resources\SeanceResource;
use App\Models\MaquetteEcue;
use App\Models\User;
use App\Services\Lmd\Assiduite;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateSeance extends CreateRecord
{
    protected static string $resource = SeanceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $auteur */
        $auteur = auth()->user();
        try {
            return app(Assiduite::class)->planifier(MaquetteEcue::query()->findOrFail((int) $data['maquette_ecue_id']), $data, $auteur);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.fin' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return SeanceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
