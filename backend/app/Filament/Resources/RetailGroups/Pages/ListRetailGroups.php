<?php

namespace App\Filament\Resources\RetailGroups\Pages;

use App\Filament\Resources\RetailGroups\RetailGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRetailGroups extends ListRecords
{
    protected static string $resource = RetailGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
