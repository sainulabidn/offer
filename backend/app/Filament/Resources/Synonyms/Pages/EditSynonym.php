<?php

namespace App\Filament\Resources\Synonyms\Pages;

use App\Filament\Resources\Synonyms\SynonymResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSynonym extends EditRecord
{
    protected static string $resource = SynonymResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
