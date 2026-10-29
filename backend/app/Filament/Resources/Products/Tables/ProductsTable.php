<?php

namespace App\Filament\Resources\Products\Tables;

use App\Domain\Catalog\ProductStatus;
use App\Filament\Support\Fields;
use App\Filament\Support\Review;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['brand', 'productGroup', 'category'])->withCount('variants'))
            ->columns([
                Fields::nameColumn(),
                TextColumn::make('brand.name')->placeholder('Unbranded'),
                TextColumn::make('productGroup.name')->label('Group'),
                TextColumn::make('category.name'),
                TextColumn::make('variants_count')->label('Variants')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                Review::column(),
                TextColumn::make('like_count')->label('Likes')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(ProductStatus::class),
                Review::filter(),
                SelectFilter::make('brand')->relationship('brand', 'slug')->searchable(),
                SelectFilter::make('category')->relationship('category', 'slug')->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                ...Review::actions('products'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
