<?php

namespace App\Filament\Resources\ProductGroups\Pages;

use App\Filament\Resources\ProductGroups\ProductGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductGroup extends EditRecord
{
    protected static string $resource = ProductGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
