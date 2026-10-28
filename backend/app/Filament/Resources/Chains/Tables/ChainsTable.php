<?php

namespace App\Filament\Resources\Chains\Tables;

use App\Domain\Retail\ChainFormat;
use App\Filament\Support\Fields;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ChainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('retailGroup')->withCount('branches'))
            ->columns([
                Fields::nameColumn(),
                TextColumn::make('format')->badge(),
                TextColumn::make('retailGroup.name')->label('Group'),
                TextColumn::make('branches_count')->label('Branches')->sortable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('format')->options(ChainFormat::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
