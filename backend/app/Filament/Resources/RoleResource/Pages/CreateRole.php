<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<string, string> */
    private array $levels = [];

    /** @var list<string> */
    private array $sensitiveActions = [];

    /**
     * `modules` et `sensitive_actions` ne sont pas des colonnes : on les met
     * de côté avant l'insert, puis on les traduit en permissions après.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->levels = (array) ($data['modules'] ?? []);
        $this->sensitiveActions = array_values((array) ($data['sensitive_actions'] ?? []));

        unset($data['modules'], $data['sensitive_actions']);

        $data['guard_name'] = 'web';

        return $data;
    }

    protected function afterCreate(): void
    {
        RoleResource::applyPerimeter($this->record, $this->levels, $this->sensitiveActions);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
