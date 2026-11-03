<?php

namespace App\Filament\Resources\Brands\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\FileUpload;
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
                Fields::belongsTo('owner_chain_id', 'ownerChain', 'Owned by chain', section: 'chains')
                    ->visible(fn (Get $get) => (bool) $get('is_private_label')),
                TagsInput::make('synonyms')
                    ->columnSpanFull()
                    ->helperText('Other spellings seen in brochures, e.g. "IG" for India Gate.'),
                FileUpload::make('logo_path')
                    ->label('Logo')
                    ->image()
                    ->disk(config('filesystems.media_disk'))
                    ->visibility('public')
                    ->directory('logos')
                    ->maxSize(2048)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->helperText('Shown on the brand\'s page and when it is shared. Square works best.'),
            ]);
    }
}
