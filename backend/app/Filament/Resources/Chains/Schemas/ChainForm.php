<?php

namespace App\Filament\Resources\Chains\Schemas;

use App\Domain\Geo\Country;
use App\Domain\Retail\ChainFormat;
use App\Filament\Support\Fields;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ChainForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Fields::translatable('name', 'Name', fillsSlug: true)->columnSpanFull(),
                Fields::slug(),
                Select::make('format')->options(ChainFormat::class)->required(),
                Fields::gradeInput(Fields::STORE_GRADES)
                    ->default(3)
                    ->selectablePlaceholder(false)
                    ->helperText('Product cards compare prices with big stores first.'),
                Fields::belongsTo('retail_group_id', 'retailGroup', 'Retail group', section: 'retail_groups'),
                Select::make('country_id')
                    ->label('Country')
                    ->relationship('country', 'code')
                    ->getOptionLabelFromRecordUsing(fn (Country $country) => $country->name)
                    ->default(fn () => Country::where('code', 'AE')->value('id'))
                    ->required(),
                Fields::belongsTo('loyalty_program_id', 'loyaltyProgram', 'Loyalty program'),
                TextInput::make('website')->url(),
                Toggle::make('is_active')->default(true)->inline(false),
                FileUpload::make('logo_path')
                    ->label('Logo')
                    ->image()
                    ->disk(config('filesystems.media_disk'))
                    ->visibility('public')
                    ->directory('logos')
                    ->maxSize(2048)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->helperText('Square works best, on a white or clear background. Without a logo, the website shows the store\'s initials.')
                    ->columnSpanFull(),
            ]);
    }
}
