<?php

namespace App\Filament\Resources\Chains\RelationManagers;

use App\Domain\Access\Access;
use App\Domain\Retail\ContentPermission;
use App\Filament\Resources\BrochureSources\Schemas\BrochureSourceForm;
use App\Filament\Resources\BrochureSources\Tables\BrochureSourcesTable;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Where this store's flyers are fetched automatically: a PDF link that stays the same, a page that links
 * to the current flyers, or a Nesto store on nestooffers.com. Checked on their own interval; new flyers are read by AI and wait for review.
 * Runs only when the store has given permission (Brochure collection → Content permission).
 * Every store's sources together: Offers → Flyer sources (BrochureSourceResource).
 */
class BrochureSourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'brochureSources';

    protected static ?string $title = 'Flyer sources';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::allows(Access::user(), 'settings', 'edit');
    }

    public function form(Schema $schema): Schema
    {
        return BrochureSourceForm::configure($schema, $this->getOwnerRecord()->id);
    }

    public function table(Table $table): Table
    {
        return BrochureSourcesTable::configure($table, allStores: false)
            ->description(fn () => $this->getOwnerRecord()->content_permission === ContentPermission::Granted
                ? null
                : 'Not fetched until the store gives permission: set Content permission to "granted" under Brochure collection.')
            ->headerActions([
                CreateAction::make()->label('Add source')->mutateDataUsing(fn (array $data) => [...$data, 'next_check_at' => now()]),
            ]);
    }
}
