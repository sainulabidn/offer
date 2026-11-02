<?php

namespace App\Filament\Pages;

use App\Domain\Access\Access;
use App\Domain\Engagement\Insights;
use App\Domain\Geo\Region;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Most viewed products and stores by area, and whether personal ranking helps. Counts only, never people. */
class ShopperInsights extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Shopper insights';

    protected static ?string $title = 'Shopper insights';

    protected ?string $subheading = 'Public counts of what shoppers look at. None of this can identify a shopper.';

    protected string $view = 'filament.pages.shopper-insights';

    public int $days = 30;

    public ?int $region = null;

    public static function canAccess(): bool
    {
        return Access::allows(Access::user(), 'settings', 'view');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $insights = app(Insights::class);

        return [
            'regions' => Region::query()->get()->mapWithKeys(fn (Region $region) => [$region->id => $region->name])->sort(),
            'products' => $insights->mostViewed('product', $this->days, $this->region),
            'stores' => $insights->mostViewed('chain', $this->days, $this->region),
            'cohorts' => $insights->cohorts($this->days),
            'minVisits' => Insights::MIN_COHORT_VISITS,
        ];
    }
}
