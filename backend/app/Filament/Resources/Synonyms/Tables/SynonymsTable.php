<?php

namespace App\Filament\Resources\Synonyms\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SynonymsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('terms')
                    ->label('Words')
                    ->badge()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereRaw('terms::text ilike ?', ['%'.mb_strtolower($search).'%'])),
                TextColumn::make('root')
                    ->label('One-way from')
                    ->placeholder('Two-way'),
                ToggleColumn::make('is_active')->label('Active'),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
