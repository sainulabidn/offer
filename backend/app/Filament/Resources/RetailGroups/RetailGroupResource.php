<?php

namespace App\Filament\Resources\RetailGroups;

use App\Domain\Retail\RetailGroup;
use App\Filament\Resources\RetailGroups\Pages\CreateRetailGroup;
use App\Filament\Resources\RetailGroups\Pages\EditRetailGroup;
use App\Filament\Resources\RetailGroups\Pages\ListRetailGroups;
use App\Filament\Resources\RetailGroups\Schemas\RetailGroupForm;
use App\Filament\Resources\RetailGroups\Tables\RetailGroupsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RetailGroupResource extends Resource
{
    protected static ?string $model = RetailGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Stores';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RetailGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RetailGroupsTable::configure($table);
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
            'index' => ListRetailGroups::route('/'),
            'create' => CreateRetailGroup::route('/create'),
            'edit' => EditRetailGroup::route('/{record}/edit'),
        ];
    }
}
