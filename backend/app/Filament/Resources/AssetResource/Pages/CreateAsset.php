<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssetResource\Pages;

use App\Filament\Resources\AssetResource;
use App\Models\Asset;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    /**
     * Disque et chemin posés côté serveur : le disque découle de la catégorie
     * (liste fermée), le chemin du fichier tout juste déposé. Rien de ce que
     * la requête pourrait contenir d'autre n'est repris.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $disk = Asset::diskForCategory((string) ($data['category'] ?? ''));
        $path = $data['upload'] ?? null;

        abort_if($disk === null || ! is_string($path) || $path === '', 422, 'Déposez un fichier.');

        return [
            ...Arr::except($data, ['upload', 'disk', 'path']),
            'disk' => $disk,
            'path' => $path,
            'filename' => filled($data['filename'] ?? null) ? $data['filename'] : basename($path),
            'uploaded_by' => auth()->id(),
        ];
    }
}
