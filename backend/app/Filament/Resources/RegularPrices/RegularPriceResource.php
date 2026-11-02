<?php

namespace App\Filament\Resources\RegularPrices;

use App\Domain\Offers\RegularPrice;
use App\Filament\Resources\RegularPrices\Pages\CreateRegularPrice;
use App\Filament\Resources\RegularPrices\Pages\EditRegularPrice;
use App\Filament\Resources\RegularPrices\Pages\ListRegularPrices;
use App\Filament\Resources\RegularPrices\Schemas\RegularPriceForm;
use App\Filament\Resources\RegularPrices\Tables\RegularPricesTable;
use App\Filament\Support\ScopedByAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RegularPriceResource extends Resource
{
    use ScopedByAccess;

    protected static ?string $model = RegularPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Stores';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Shelf prices';

    protected static ?string $modelLabel = 'shelf price';

    public static function form(Schema $schema): Schema
    {
        return RegularPriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegularPricesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegularPrices::route('/'),
            'create' => CreateRegularPrice::route('/create'),
            'edit' => EditRegularPrice::route('/{record}/edit'),
        ];
    }
}
