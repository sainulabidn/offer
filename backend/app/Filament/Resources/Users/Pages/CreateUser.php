<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use ManagesStoreAccess;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->takeStoreFields($data);
    }

    protected function afterCreate(): void
    {
        // Admin accounts are made by staff, so the address counts as checked.
        $this->record->forceFill(['email_verified_at' => now()])->save();
        $this->saveStores($this->record);
    }
}
