<?php

declare(strict_types=1);

namespace App\Filament\Resources\EpreuveAnonymeResource\Pages;

use App\Filament\Resources\EpreuveAnonymeResource;
use App\Models\MaquetteEcue;
use App\Models\User;
use App\Services\Lmd\Anonymat;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateEpreuveAnonyme extends CreateRecord
{
    protected static string $resource = EpreuveAnonymeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $auteur */
        $auteur = auth()->user();
        try {
            return app(Anonymat::class)->creer(MaquetteEcue::query()->findOrFail((int) $data['maquette_ecue_id']), (string) $data['session'], $auteur);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.maquette_ecue_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return EpreuveAnonymeResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
