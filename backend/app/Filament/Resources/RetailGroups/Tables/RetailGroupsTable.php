<?php

namespace App\Filament\Resources\RetailGroups\Tables;

use App\Filament\Support\Fields;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RetailGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['chains', 'operatedBranches']))
            ->columns([
                Fields::nameColumn(),
                TextColumn::make('chains_count')->label('Chains'),
                TextColumn::make('operated_branches_count')->label('Affiliate branches'),
                TextColumn::make('website')->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
