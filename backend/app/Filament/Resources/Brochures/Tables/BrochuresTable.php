<?php

namespace App\Filament\Resources\Brochures\Tables;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Filament\Support\Fields;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BrochuresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Direct offers have their own page (Offers); their hidden containers aren't brochures to manage.
            ->modifyQueryUsing(fn ($query) => $query->where('type', '!=', Brochure::TYPE_DIRECT)->with('chain')->withCount('offers'))
            ->columns([
                TextColumn::make('chain.name')->label('Chain'),
                Fields::nameColumn('title'),
                TextColumn::make('valid_from')->date('d M')->label('From')->sortable(),
                TextColumn::make('valid_to')->date('d M Y')->label('To')->sortable(),
                TextColumn::make('offers_count')->label('Offers'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('valid_from', 'desc')
            ->filters([
                SelectFilter::make('status')->options(BrochureStatus::class),
                SelectFilter::make('chain')->relationship('chain', 'slug')->searchable()->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
