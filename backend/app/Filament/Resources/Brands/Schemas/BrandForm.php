<?php

namespace App\Filament\Resources\Brands\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Fields::translatable('name', 'Name', fillsSlug: true)->columnSpanFull(),
                Fields::slug(),
                TextInput::make('manufacturer'),
                Toggle::make('is_private_label')
                    ->label('Store own brand (private label)')
                    ->live()
                    ->inline(false),
                Fields::belongsTo('owner_chain_id', 'ownerChain', 'Owned by chain')
                    ->visible(fn (Get $get) => (bool) $get('is_private_label')),
                TagsInput::make('synonyms')
                    ->columnSpanFull()
                    ->helperText('Other spellings seen in brochures, e.g. "IG" for India Gate.'),
            ]);
    }
}
