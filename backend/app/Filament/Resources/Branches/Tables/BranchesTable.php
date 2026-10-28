<?php

namespace App\Filament\Resources\Branches\Tables;

use App\Domain\Retail\Branch;
use App\Filament\Support\Fields;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BranchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['chain', 'region']))
            ->columns([
                TextColumn::make('chain.name')->label('Chain'),
                Fields::nameColumn(),
                TextColumn::make('region.name')->label('Area'),
                TextColumn::make('coordinates')
                    ->state(fn (Branch $branch) => sprintf('%.5f, %.5f', $branch->location->getLatitude(), $branch->location->getLongitude()))
                    ->url(fn (Branch $branch) => sprintf('https://www.google.com/maps?q=%F,%F', $branch->location->getLatitude(), $branch->location->getLongitude()), shouldOpenInNewTab: true),
                IconColumn::make('is_affiliate')->label('Affiliate')->boolean(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('chain')->relationship('chain', 'slug')->searchable()->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
