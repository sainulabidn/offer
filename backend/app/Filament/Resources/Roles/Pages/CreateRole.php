<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @var list<string> */
    private array $permissionNames = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permissionNames = RoleForm::namesFrom($data['grid'] ?? []);
        unset($data['grid']);

        return [...$data, 'guard_name' => 'web'];
    }

    protected function afterCreate(): void
    {
        $this->record->syncPermissions($this->permissionNames);
    }
}
