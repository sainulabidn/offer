<?php

namespace App\Filament\Resources\Chains\Pages;

use App\Filament\Resources\Chains\ChainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChains extends ListRecords
{
    protected static string $resource = ChainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
