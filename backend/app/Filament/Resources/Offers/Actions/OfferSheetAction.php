<?php

namespace App\Filament\Resources\Offers\Actions;

use App\Domain\Access\Access;
use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Retail\RetailGroup;
use App\Filament\Imports\OfferImportGuide;
use App\Filament\Imports\OfferSheet;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Support\OfferFields;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * "Prepare offer sheet": pick stores and branches, the dates (and times) and texts shared by every offer,
 * and which products; download a CSV with a row per store and pack, ready for prices (OfferSheet).
 */
final class OfferSheetAction
{
    public static function make(): Action
    {
        $user = fn () => Access::user();
        $options = fn (Get $get) => [
            'chain_ids' => $get('chain_ids') ?? [], 'branch_ids' => $get('branch_ids') ?? [], 'category_ids' => $get('category_ids') ?? [],
            'brand_ids' => $get('brand_ids') ?? [], 'product_group_ids' => $get('product_group_ids') ?? [], 'search' => $get('search'),
        ];
        $groups = fn () => Access::scope(RetailGroup::query(), Access::user(), 'retail_groups')->get()->sortBy('name')->mapWithKeys(fn (RetailGroup $g) => [$g->id => $g->name]);

        return Action::make('offerSheet')
            ->label('Prepare offer sheet')
            ->icon(Heroicon::OutlinedTableCells)
            ->color('gray')
            ->visible(fn () => OfferResource::canCreate())
            ->modalHeading('Prepare an offer sheet')
            ->modalDescription('A CSV with a row for every store and pack you choose, with dates and texts filled in. Type the prices, then use "Import offers (CSV)". Rows you leave without a price are skipped.')
            ->modalSubmitActionLabel('Download CSV')
            ->modalWidth('3xl')
            ->steps([
                Step::make('Stores')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->schema([
                        Select::make('retail_group_ids')
                            ->label('Retail groups')
                            ->multiple()
                            ->options($groups)
                            ->visible(fn () => $groups()->count() > 1)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('chain_ids', []))
                            ->helperText('Optional: narrows the stores below.'),
                        Select::make('chain_ids')
                            ->label('Stores')
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->options(fn (Get $get) => OfferImportGuide::chains($user())
                                ->when($get('retail_group_ids'), fn ($q, array $ids) => $q->whereIn('retail_group_id', $ids))
                                ->get()->sortBy('name')->mapWithKeys(fn ($chain) => [$chain->id => $chain->name]))
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('branch_ids', [])),
                        Select::make('branch_ids')
                            ->label('Branches')
                            ->multiple()
                            ->searchable()
                            ->options(fn (Get $get) => OfferSheet::branchOptions($user(), $get('chain_ids') ?? []))
                            ->helperText('Empty = every branch of each store (or every branch you work at).'),
                    ]),
                Step::make('Dates and details')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->description('The same for every row; you can still change any row in the CSV.')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('valid_from')->label('From')->native(false)->required()
                            ->default(fn () => now(config('app.market_timezone'))->toDateString()),
                        DatePicker::make('valid_to')->label('Until')->native(false)->required()->afterOrEqual('valid_from')
                            ->minDate(fn () => now(config('app.market_timezone'))->toDateString()),
                        Grid::make(3)->columnSpanFull()->schema(OfferFields::times()),
                        TextInput::make('promotion_text')->placeholder('e.g. Weekend deal'),
                        TextInput::make('max_per_customer')->integer()->minValue(1),
                        TextInput::make('limit_text')->placeholder('e.g. Max 3 kg per customer'),
                        TextInput::make('limit_text_ar')->label('Limit text (Arabic)')->extraInputAttributes(['dir' => 'rtl']),
                        Toggle::make('employees_only')->label('Store employees only'),
                        Toggle::make('loyalty_card')->label('Loyalty card price'),
                    ]),
                Step::make('Products')
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->description('Leave everything empty for every product.')
                    ->columns(2)
                    ->schema([
                        Select::make('category_ids')->label('Categories')->multiple()->searchable()->live()
                            ->options(fn () => Category::query()->orderByRaw("path || id::text || '/'")->get()
                                ->mapWithKeys(fn (Category $c) => [$c->id => str_repeat('— ', (int) $c->depth).$c->name]))
                            ->helperText('Includes their subcategories.'),
                        Select::make('brand_ids')->label('Brands')->multiple()->searchable()->live()
                            ->options(fn () => Brand::query()->get()->sortBy('name')->mapWithKeys(fn (Brand $b) => [$b->id => $b->name])),
                        Select::make('product_group_ids')->label('Product groups')->multiple()->searchable()->live()
                            ->options(fn () => ProductGroup::query()->get()->sortBy('name')->mapWithKeys(fn (ProductGroup $g) => [$g->id => $g->name])),
                        TextInput::make('search')->label('Name or barcode contains')->live(debounce: 500),
                        Toggle::make('only_priced')->label('Only packs the store has a shelf price for')
                            ->helperText('Shows that price in the "usual_price" column either way.')
                            ->columnSpanFull(),
                        Text::make(fn (Get $get) => OfferSheet::summary($user(), $options($get)))
                            ->weight('bold')
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    return OfferSheet::download(Access::user(), $data);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }
            });
    }
}
