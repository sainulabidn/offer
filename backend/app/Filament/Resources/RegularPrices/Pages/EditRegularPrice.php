<?php

namespace App\Filament\Resources\RegularPrices\Pages;

use App\Filament\Resources\RegularPrices\RegularPriceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegularPrice extends EditRecord
{
    protected static string $resource = RegularPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
