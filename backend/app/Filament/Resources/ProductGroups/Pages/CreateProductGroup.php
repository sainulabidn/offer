<?php

namespace App\Filament\Resources\ProductGroups\Pages;

use App\Filament\Resources\ProductGroups\ProductGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductGroup extends CreateRecord
{
    protected static string $resource = ProductGroupResource::class;
}
