<?php

namespace App\Filament\Resources\ProductGroups\Schemas;

use App\Domain\Catalog\Unit;
use App\Filament\Support\Fields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Fields::translatable('name', 'Name', fillsSlug: true)->columnSpanFull(),
                Fields::slug(),
                Fields::belongsTo('category_id', 'category', 'Category', section: 'categories')->required(),
                TextInput::make('comparison_quantity')
                    ->label('Compare per quantity')
                    ->numeric()
                    ->gt(0)
                    ->default(1)
                    ->required()
                    ->helperText('1 for "per kg", 100 for "per 100 sheets".'),
                Select::make('comparison_unit_code')
                    ->label('Compare per unit')
                    ->options(fn () => Unit::query()->orderBy('dimension')->orderBy('factor_to_base')->pluck('code', 'code'))
                    ->required(),
                TagsInput::make('synonyms')
                    ->columnSpanFull()
                    ->helperText('Search words in any language, e.g. chawal, أرز.'),
            ]);
    }
}
