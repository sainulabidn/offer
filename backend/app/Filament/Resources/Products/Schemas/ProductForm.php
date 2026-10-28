<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Domain\Catalog\ProductStatus;
use App\Filament\Support\Fields;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Fields::translatable('name', 'Name', fillsSlug: true)->columnSpanFull(),
                        Fields::slug(),
                        Select::make('status')
                            ->options(ProductStatus::class)
                            ->default(ProductStatus::Draft)
                            ->required(),
                        Fields::belongsTo('brand_id', 'brand', 'Brand')
                            ->helperText('Leave empty for unbranded fresh items such as bananas.'),
                        Fields::belongsTo('product_group_id', 'productGroup', 'Product group')
                            ->required()
                            ->helperText('The generic item shoppers compare across brands, e.g. "Basmati Rice".'),
                        Fields::belongsTo('category_id', 'category', 'Category')->required(),
                        Toggle::make('is_variable_weight')
                            ->label('Sold by weight (priced per kg)')
                            ->inline(false),
                    ]),
                Section::make('Images')
                    ->columnSpanFull()
                    ->description('The first image shows on cards. JPG, PNG or WebP up to 8 MB; smaller AVIF/WebP copies are made automatically.')
                    ->schema([
                        Repeater::make('images')
                            ->relationship()
                            ->hiddenLabel()
                            ->orderColumn('sort')
                            ->reorderable()
                            ->grid(3)
                            ->defaultItems(0)
                            ->addActionLabel('Add image')
                            ->schema([
                                FileUpload::make('path')
                                    ->hiddenLabel()
                                    ->image()
                                    ->disk(config('filesystems.media_disk'))
                                    ->visibility('public')
                                    ->directory('products')
                                    ->maxSize(8192)
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->required(),
                                TextInput::make('alt.en')->label('Description (for screen readers)'),
                            ]),
                    ]),
                Section::make('Details')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Fields::translatable('description', 'Description', required: false, textarea: true),
                        KeyValue::make('attributes')
                            ->label('Attributes')
                            ->keyLabel('Attribute code')
                            ->valueLabel('Value')
                            ->helperText('Descriptive values such as origin = IN, organic = yes. Variations go under Options and Variants below.'),
                    ]),
            ]);
    }
}
