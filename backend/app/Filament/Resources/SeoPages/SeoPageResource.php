<?php

namespace App\Filament\Resources\SeoPages;

use App\Domain\Seo\SeoPage;
use App\Filament\Resources\SeoPages\Pages\CreateSeoPage;
use App\Filament\Resources\SeoPages\Pages\EditSeoPage;
use App\Filament\Resources\SeoPages\Pages\ListSeoPages;
use App\Filament\Resources\SeoPages\Schemas\SeoPageForm;
use App\Filament\Resources\SeoPages\Tables\SeoPagesTable;
use App\Filament\Support\ScopedByAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SeoPageResource extends Resource
{
    use ScopedByAccess;

    protected static ?string $model = SeoPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'SEO pages';

    protected static ?string $modelLabel = 'SEO page';

    protected static ?string $recordTitleAttribute = 'path';

    public static function form(Schema $schema): Schema
    {
        return SeoPageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SeoPagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeoPages::route('/'),
            'create' => CreateSeoPage::route('/create'),
            'edit' => EditSeoPage::route('/{record}/edit'),
        ];
    }
}
