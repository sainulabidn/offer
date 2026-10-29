<?php

namespace App\Filament\Resources\Chains;

use App\Domain\Retail\Chain;
use App\Filament\Resources\Chains\Pages\CreateChain;
use App\Filament\Resources\Chains\Pages\EditChain;
use App\Filament\Resources\Chains\Pages\ListChains;
use App\Filament\Resources\Chains\Schemas\ChainForm;
use App\Filament\Resources\Chains\Tables\ChainsTable;
use App\Filament\Support\ScopedByAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ChainResource extends Resource
{
    use ScopedByAccess;

    protected static ?string $model = Chain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Stores';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ChainForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChainsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChains::route('/'),
            'create' => CreateChain::route('/create'),
            'edit' => EditChain::route('/{record}/edit'),
        ];
    }
}
