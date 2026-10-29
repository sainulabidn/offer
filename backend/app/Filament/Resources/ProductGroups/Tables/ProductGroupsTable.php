<?php

namespace App\Filament\Resources\ProductGroups\Tables;

use App\Domain\Catalog\ProductGroup;
use App\Filament\Support\Fields;
use App\Filament\Support\Review;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('category')->withCount('products'))
            ->columns([
                Fields::nameColumn(),
                TextColumn::make('category.name'),
                TextColumn::make('compares')
                    ->label('Compared per')
                    ->state(fn (ProductGroup $group) => rtrim(rtrim((string) $group->comparison_quantity, '0'), '.').' '.$group->comparison_unit_code),
                TextColumn::make('products_count')->label('Products')->sortable(),
                Review::column(),
            ])
            ->filters([Review::filter()])
            ->recordActions([
                EditAction::make(),
                ...Review::actions('product_groups'),
            ]);
    }
}
