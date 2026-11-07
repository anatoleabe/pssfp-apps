<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliberationResource\Pages;

use App\Filament\Resources\DeliberationResource;
use App\Models\User;
use App\Services\Lmd\Deliberations;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateDeliberation extends CreateRecord
{
    protected static string $resource = DeliberationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $auteur */
        $auteur = auth()->user();
        try {
            return app(Deliberations::class)->creer($data, $auteur);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['data.session' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return DeliberationResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
