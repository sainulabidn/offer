<?php

namespace App\Filament\Resources\Synonyms\Pages;

use App\Filament\Resources\Synonyms\SynonymResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSynonym extends CreateRecord
{
    protected static string $resource = SynonymResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
