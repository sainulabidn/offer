<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\UserStoreAccess;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use ManagesStoreAccess;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $ids = UserStoreAccess::idsFor($this->record);

        return [
            ...$data,
            'store_groups' => $ids['retail_group'],
            'store_chains' => $ids['chain'],
            'store_branches' => $ids['branch'],
            'is_disabled' => $this->record->disabled_at !== null,
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->takeStoreFields($data);
    }

    protected function afterSave(): void
    {
        $this->saveStores($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
