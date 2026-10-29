<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var list<string> */
    private array $permissionNames = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'grid' => RoleForm::gridFrom($this->record->permissions()->pluck('name'))];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissionNames = RoleForm::namesFrom($data['grid'] ?? []);
        unset($data['grid']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncPermissions($this->permissionNames);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
