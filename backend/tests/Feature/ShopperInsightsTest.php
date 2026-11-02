<?php

use App\Domain\Engagement\Insights;
use App\Domain\Geo\Country;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ChainFormat;
use App\Domain\Retail\RetailGroup;
use App\Filament\Pages\ShopperInsights;
use App\Models\User;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

function counted(string $kind, int $id, int $daysAgo, int $views, int $clicks = 0, int $region = 0): void
{
    DB::table('engagement_daily')->insert([
        'day' => now(config('app.market_timezone'))->subDays($daysAgo)->toDateString(),
        'kind' => $kind,
        'target_id' => $id,
        'region_id' => $region,
        'views' => $views,
        'clicks' => $clicks,
    ]);
}

function insightChain(string $name): Chain
{
    return Chain::create([
        'retail_group_id' => RetailGroup::create(['name' => ['en' => "{$name} Group"], 'slug' => str($name)->slug().'-group'])->id,
        'country_id' => Country::where('code', 'AE')->value('id'),
        'name' => ['en' => $name],
        'slug' => str($name)->slug(),
        'format' => ChainFormat::Hypermarket,
    ]);
}

it('orders most viewed by interest, within the area and period', function () {
    $this->seed(GeoSeeder::class);
    $lulu = insightChain('Lulu');
    $nesto = insightChain('Nesto');
    counted('chain', $lulu->id, 1, 5, 10, region: 3);
    counted('chain', $nesto->id, 1, 20, 0, region: 3);
    counted('chain', $nesto->id, 1, 900, 0, region: 4);
    counted('chain', $lulu->id, 40, 900, 0, region: 3);

    $rows = app(Insights::class)->mostViewed('chain', 30, 3);

    expect(array_column($rows, 'name'))->toBe(['Lulu', 'Nesto'])
        ->and($rows[0]['views'])->toBe(5);
});

it('finds what is rising, and ignores steady and tiny counts', function () {
    counted('product', 1, 1, 30);
    counted('product', 2, 1, 30);
    counted('product', 3, 1, 3);
    foreach (range(4, 15) as $day) {
        counted('product', 2, $day, 50);
    }

    expect(app(Insights::class)->trending(null))->toBe([1]);
});

it('serves trending to anyone, kept to one area', function () {
    counted('product', 7, 0, 40, region: 3);
    counted('product', 8, 0, 40, region: 4);

    getJson('/api/v1/trending?region=3')->assertOk()->assertExactJson([7]);
});

it('says when the comparison groups are too small', function () {
    counted('cohort', 1, 1, 2000, 300);
    counted('cohort', 2, 1, 200, 20);

    $cohorts = app(Insights::class)->cohorts(30);

    expect($cohorts['enough'])->toBeFalse()
        ->and($cohorts['personal']['rate'])->toBe(0.15)
        ->and($cohorts['shared']['rate'])->toBe(0.1);
});

it('shows the page only to people with the settings permission', function () {
    $this->seed([GeoSeeder::class, StaffSeeder::class]);
    signInAdmin(User::factory()->create());
    expect(ShopperInsights::canAccess())->toBeFalse();

    $chain = insightChain('Viva');
    counted('chain', $chain->id, 1, 10);
    signInAdmin(tap(User::factory()->create())->assignRole('super_admin'));

    livewire(ShopperInsights::class)->assertSuccessful()->assertSee('Viva')->assertSee('Not enough data yet');
});
