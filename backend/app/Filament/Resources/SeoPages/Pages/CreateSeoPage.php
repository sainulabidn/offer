<?php

namespace App\Filament\Resources\SeoPages\Pages;

use App\Filament\Resources\SeoPages\SeoPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSeoPage extends CreateRecord
{
    protected static string $resource = SeoPageResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
