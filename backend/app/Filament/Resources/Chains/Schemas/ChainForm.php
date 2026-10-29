<?php

namespace App\Filament\Resources\Chains\Schemas;

use App\Domain\Geo\Country;
use App\Domain\Retail\ChainFormat;
use App\Filament\Support\Fields;
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
            ]);
    }
}
