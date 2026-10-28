<?php

namespace App\Filament\Resources\RetailGroups\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RetailGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Fields::translatable('name', 'Name', fillsSlug: true)->columnSpanFull(),
                Fields::slug(),
                TextInput::make('website')->url(),
            ]);
    }
}
