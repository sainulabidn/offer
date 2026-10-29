<?php

namespace App\Filament\Resources\Brochures;

use App\Domain\Brochures\Brochure;
use App\Filament\Resources\Brochures\Pages\CreateBrochure;
use App\Filament\Resources\Brochures\Pages\EditBrochure;
use App\Filament\Resources\Brochures\Pages\ListBrochures;
use App\Filament\Resources\Brochures\RelationManagers\OffersRelationManager;
use App\Filament\Resources\Brochures\Schemas\BrochureForm;
use App\Filament\Resources\Brochures\Tables\BrochuresTable;
use App\Filament\Support\ScopedByAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BrochureResource extends Resource
{
    use ScopedByAccess;

    protected static ?string $model = Brochure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return BrochureForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrochuresTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OffersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrochures::route('/'),
            'create' => CreateBrochure::route('/create'),
            'edit' => EditBrochure::route('/{record}/edit'),
        ];
    }
}
