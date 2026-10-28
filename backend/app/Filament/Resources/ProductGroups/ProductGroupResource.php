<?php

namespace App\Filament\Resources\ProductGroups;

use App\Domain\Catalog\ProductGroup;
use App\Filament\Resources\ProductGroups\Pages\CreateProductGroup;
use App\Filament\Resources\ProductGroups\Pages\EditProductGroup;
use App\Filament\Resources\ProductGroups\Pages\ListProductGroups;
use App\Filament\Resources\ProductGroups\Schemas\ProductGroupForm;
use App\Filament\Resources\ProductGroups\Tables\ProductGroupsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductGroupResource extends Resource
{
    protected static ?string $model = ProductGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProductGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductGroupsTable::configure($table);
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
            'index' => ListProductGroups::route('/'),
            'create' => CreateProductGroup::route('/create'),
            'edit' => EditProductGroup::route('/{record}/edit'),
        ];
    }
}
