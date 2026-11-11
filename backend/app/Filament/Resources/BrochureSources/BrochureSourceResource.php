<?php

namespace App\Filament\Resources\BrochureSources;

use App\Domain\Access\Access;
use App\Domain\Flyers\Sources\BrochureSource;
use App\Filament\Resources\BrochureSources\Pages\ManageBrochureSources;
use App\Filament\Resources\BrochureSources\Schemas\BrochureSourceForm;
use App\Filament\Resources\BrochureSources\Tables\BrochureSourcesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every store's flyer sources on one page, with filters (failing, paused, quiet, no permission …). The same
 * sources are also on each store's page (Chains → Flyer sources). For settings editors, as there.
 */
class BrochureSourceResource extends Resource
{
    protected static ?string $model = BrochureSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRss;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Flyer sources';

    protected static ?string $modelLabel = 'flyer source';

    protected static ?string $recordTitleAttribute = 'name';

    /** Sources have no section of their own: everything here is for settings editors, as on the store's page. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return Access::allows(Access::user(), 'settings', 'edit') ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return BrochureSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrochureSourcesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrochureSources::route('/'),
        ];
    }
}
