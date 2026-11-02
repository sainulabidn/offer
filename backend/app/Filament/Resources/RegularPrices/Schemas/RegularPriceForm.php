<?php

namespace App\Filament\Resources\RegularPrices\Schemas;

use App\Domain\Catalog\ProductVariant;
use App\Filament\Support\Fields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RegularPriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('product_variant_id')
                    ->label('Product and pack')
                    ->searchable()
                    ->required()
                    ->columnSpanFull()
                    ->getSearchResultsUsing(fn (string $search) => ProductVariant::query()
                        ->whereHas('product', fn ($query) => $query->whereRaw("name->>'en' ilike ?", ['%'.$search.'%']))
                        ->with(['product', 'optionValues'])
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (ProductVariant $variant) => [$variant->id => $variant->label()]))
                    ->getOptionLabelUsing(fn ($value) => ProductVariant::with(['product', 'optionValues'])->find($value)?->label()),
                Fields::belongsTo('chain_id', 'chain', 'Store', section: 'chains')->required(),
                TextInput::make('price')->label('Shelf price (AED)')->numeric()->gt(0)->maxValue(99999)->required(),
                DatePicker::make('observed_at')->label('Seen on')->default(fn () => now(config('app.market_timezone')))->maxDate(fn () => now(config('app.market_timezone')))->required(),
            ]);
    }
}
