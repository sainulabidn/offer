<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Fields::translatable('name', 'Name', fillsSlug: true)->columnSpanFull(),
                Fields::slug(),
                Fields::belongsTo('parent_id', 'parent', 'Parent category')
                    ->helperText('Empty for a top-level category.'),
                TextInput::make('icon')->helperText('Optional icon name for the website menu.'),
                TextInput::make('sort')->numeric()->default(0),
                Toggle::make('is_active')->default(true)->inline(false),
            ]);
    }
}
