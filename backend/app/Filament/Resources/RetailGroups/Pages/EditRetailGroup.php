<?php

namespace App\Filament\Resources\RetailGroups\Pages;

use App\Filament\Resources\RetailGroups\RetailGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRetailGroup extends EditRecord
{
    protected static string $resource = RetailGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
