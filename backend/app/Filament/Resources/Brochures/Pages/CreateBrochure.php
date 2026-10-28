<?php

namespace App\Filament\Resources\Brochures\Pages;

use App\Filament\Resources\Brochures\BrochureResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBrochure extends CreateRecord
{
    protected static string $resource = BrochureResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    /** Go straight to the edit page, where offers are entered. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
