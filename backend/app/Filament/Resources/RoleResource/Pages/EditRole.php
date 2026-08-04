<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Support\ModulePermissionMap;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<string, string> */
    private array $levels = [];

    /** @var list<string> */
    private array $sensitiveActions = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Reconstitue les niveaux par module et les actions sensibles à partir des
     * permissions réellement attachées au rôle.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $permissions = $this->record->permissions->pluck('name')->all();

        $data['modules'] = ModulePermissionMap::toLevels($permissions);
        $data['sensitive_actions'] = array_values(array_intersect(
            array_keys(ModulePermissionMap::sensitiveActions()),
            $permissions,
        ));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->levels = (array) ($data['modules'] ?? []);
        $this->sensitiveActions = array_values((array) ($data['sensitive_actions'] ?? []));

        unset($data['modules'], $data['sensitive_actions']);

        return $data;
    }

    protected function afterSave(): void
    {
        RoleResource::applyPerimeter($this->record, $this->levels, $this->sensitiveActions);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
