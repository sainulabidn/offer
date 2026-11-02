<?php

namespace App\Filament\Pages;

use App\Domain\Access\Access;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Offers\ShelfPrices;
use App\Domain\Retail\Chain;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The staff worklist: daily items on offer somewhere that this store has no recent price for, so
 * their product cards have a gap. Type a price and the row is done; nothing else needs a visit.
 */
class MissingShelfPrices extends Page implements HasTable
{
    use InteractsWithTable;

    private const int DAILY_ESSENTIAL = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Stores';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Missing shelf prices';

    protected static ?string $title = 'Missing shelf prices';

    public static function canAccess(): bool
    {
        return Access::allows(Access::user(), 'shelf_prices', 'create');
    }

    /** The coverage report: whether this work is paying off. */
    public function getSubheading(): ?string
    {
        $coverage = app(ShelfPrices::class)->coverage();

        return "{$coverage['covered']} of {$coverage['items']} daily items on offer have prices from at least 3 big stores. {$coverage['no_photo']} have no photo.";
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /** @return Collection<int, string> big stores this person may enter prices for */
    private function stores(): Collection
    {
        return Access::scope(Chain::query()->where('is_active', true)->where('grade', '<=', ShelfPrices::COMPARED_GRADE), Access::user(), 'chains')
            ->orderBy('grade')->orderBy('slug')
            ->get()
            ->mapWithKeys(fn (Chain $chain) => [$chain->id => $chain->name]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ProductVariant::query()
                ->whereHas('offers', fn (Builder $offers) => $offers->live())
                ->whereHas('product', fn (Builder $product) => $product->approved()
                    ->where('status', ProductStatus::Active)
                    ->where(fn (Builder $grade) => $grade
                        ->where('products.grade', self::DAILY_ESSENTIAL)
                        ->orWhere(fn (Builder $inherited) => $inherited
                            ->whereNull('products.grade')
                            ->whereHas('productGroup', fn (Builder $group) => $group->where('grade', self::DAILY_ESSENTIAL)))))
                ->with(['product.productGroup', 'optionValues']))
            ->columns([
                TextColumn::make('item')->label('Product and pack')->state(fn (ProductVariant $variant) => $variant->label()),
                TextColumn::make('product.productGroup.name')->label('Group'),
                TextInputColumn::make('shelf_price')
                    ->label('Shelf price today (AED)')
                    ->type('number')
                    ->rules(['required', 'numeric', 'gt:0', 'max:99999'])
                    ->getStateUsing(fn () => null)
                    ->updateStateUsing(function (ProductVariant $record, $state) {
                        $this->savePrice($record, (string) $state);
                    }),
            ])
            ->filters([
                SelectFilter::make('store')
                    ->options(fn () => $this->stores())
                    ->default(fn () => $this->stores()->keys()->first())
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['value'] ?? null, fn (Builder $query, $chainId) => $query
                            ->whereDoesntHave('recentRegularPrices', fn (Builder $prices) => $prices->where('chain_id', $chainId))
                            ->whereDoesntHave('offers', fn (Builder $offers) => $offers->live()->where('chain_id', $chainId)))),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('Nothing missing for this store')
            ->emptyStateDescription('Every daily item on offer has a price here.');
    }

    private function savePrice(ProductVariant $variant, string $price): void
    {
        $chainId = (int) ($this->getTableFilterState('store')['value'] ?? 0);
        // The chosen store comes from the browser, so it's checked again here.
        abort_unless($this->stores()->has($chainId), 403);

        app(ShelfPrices::class)->record($variant->id, $chainId, $price);
        Notification::make()->success()->title('Saved')->send();
    }
}
