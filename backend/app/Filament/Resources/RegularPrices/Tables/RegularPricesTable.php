<?php

namespace App\Filament\Resources\RegularPrices\Tables;

use App\Domain\Offers\RegularPrice;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RegularPricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['variant.product', 'variant.optionValues', 'chain']))
            ->columns([
                TextColumn::make('variant')->label('Product and pack')->state(fn (RegularPrice $price) => $price->variant->label()),
                TextColumn::make('chain.name')->label('Store'),
                TextColumn::make('price')->label('AED')->sortable(),
                TextColumn::make('observed_at')->label('Seen on')->date()->sortable(),
                TextColumn::make('source')->badge(),
            ])
            ->defaultSort('observed_at', 'desc')
            ->filters([
                SelectFilter::make('chain')->label('Store')->relationship('chain', 'slug')->searchable(),
                SelectFilter::make('source')->options([RegularPrice::SOURCE_STAFF => 'Staff', RegularPrice::SOURCE_WAS_PRICE => 'Brochure "was" price']),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
