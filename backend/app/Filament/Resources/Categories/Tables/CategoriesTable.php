<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Domain\Catalog\Category;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('parent')->withCount('children')
                // Tree order: each parent followed by its children.
                ->orderByRaw("path || id || '/'"))
            ->columns([
                TextColumn::make('name')
                    ->formatStateUsing(fn (string $state, Category $record) => str_repeat('— ', $record->depth).$state)
                    ->searchable(query: fn ($query, string $search) => $query->whereRaw("name->>'en' ilike ?", ['%'.$search.'%'])),
                TextColumn::make('slug')->toggleable(),
                TextColumn::make('children_count')->label('Subcategories'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->paginated([25, 50, 100])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
