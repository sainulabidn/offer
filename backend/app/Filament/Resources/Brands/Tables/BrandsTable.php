<?php

namespace App\Filament\Resources\Brands\Tables;

use App\Filament\Support\Fields;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('products'))
            ->columns([
                Fields::nameColumn(),
                TextColumn::make('manufacturer')->toggleable(),
                IconColumn::make('is_private_label')->label('Own brand')->boolean(),
                TextColumn::make('products_count')->label('Products')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
