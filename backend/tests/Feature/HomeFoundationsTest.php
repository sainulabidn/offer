<?php

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\EssentialGrades;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Catalog\ProductStat;
use App\Domain\Engagement\Counters;
use App\Domain\Offers\ExpireOffers;
use App\Domain\Offers\Offer;
use App\Domain\Offers\RegularPrice;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Search\RankSync;
use App\Domain\Settings\HomeSettings;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;

// Runs against the real Typesense container, in the test_offers collection.
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dubai'));
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();

    $this->chain = Chain::firstOrFail();
    $this->branch = $this->chain->branches()->firstOrFail();
    $this->rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    $this->riceFiveKg = $this->rice->variants()->where('slug', '5-kg')->value('id');
});

afterEach(fn () => Offer::removeAllFromSearch());

function brochure(Chain $chain, string $slug, string $from, string $to): Brochure
{
    return Brochure::create(['chain_id' => $chain->id, 'title' => ['en' => $slug], 'slug' => $slug, 'valid_from' => $from, 'valid_to' => $to]);
}

function searchDocs(array $options = []): array
{
    return Offer::search('*')->options($options)->raw()['hits'] ?? [];
}

it('keeps a scheduled brochure out of search until its first day', function () {
    $brochure = brochure($this->chain, 'next-week', '2026-10-27', '2026-11-02');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);

    app(PublishBrochure::class)($brochure);

    expect($brochure->fresh()->status)->toBe(BrochureStatus::Scheduled)
        ->and(searchDocs())->toBeEmpty();

    // Just after midnight in Dubai is still the previous day in UTC.
    Carbon::setTestNow(Carbon::parse('2026-10-27 00:05', 'Asia/Dubai'));
    $result = app(ExpireOffers::class)();

    expect($result['started'])->toBe(1)
        ->and($brochure->fresh()->status)->toBe(BrochureStatus::Published)
        ->and(searchDocs())->toHaveCount(1);
});

it('indexes an offer that starts after its brochure on its own first day', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95', 'valid_from' => '2026-10-26']);

    app(PublishBrochure::class)($brochure);
    expect(searchDocs())->toBeEmpty();

    Carbon::setTestNow(Carbon::parse('2026-10-26 00:05', 'Asia/Dubai'));
    app(ExpireOffers::class)();

    expect(searchDocs())->toHaveCount(1);
});

it('catches up when the midnight job missed a day', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-25', '2026-10-31');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    app(PublishBrochure::class)($brochure);

    Carbon::setTestNow(Carbon::parse('2026-10-27 00:05', 'Asia/Dubai'));
    app(ExpireOffers::class)();

    expect(searchDocs())->toHaveCount(1);
});

it('keeps an offer searchable through its last day in Dubai', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-26');
    $offer = Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    app(PublishBrochure::class)($brochure);

    Carbon::setTestNow(Carbon::parse('2026-10-26 23:30', 'Asia/Dubai'));
    expect($offer->fresh()->shouldBeSearchable())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-10-27 00:01', 'Asia/Dubai'));
    expect($offer->fresh()->shouldBeSearchable())->toBeFalse();
});

it('records "was" prices as usual prices when publishing, without the inflated ones', function () {
    $past = brochure($this->chain, 'last-month', '2026-09-20', '2026-09-26');
    Offer::create(['brochure_id' => $past->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '48.00', 'regular_price' => '50.00']);
    app(PublishBrochure::class)($past);
    app(ExpireOffers::class)();

    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95', 'regular_price' => '90.00']);
    app(PublishBrochure::class)($brochure);

    expect(RegularPrice::pluck('price', 'observed_at')->mapWithKeys(fn ($price, $day) => [substr($day, 0, 10) => $price])->all())
        ->toBe(['2026-09-20' => '50.00']);
});

it('keeps one usual price when a brochure lists a pack twice', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95', 'regular_price' => '54.50']);
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '44.00', 'regular_price' => '54.50']);

    app(PublishBrochure::class)($brochure);

    expect(RegularPrice::count())->toBe(1);
});

it('shows other stores\' usual prices on an offer, not its own', function () {
    $other = $this->chain->replicate(['slug'])->fill(['name' => ['en' => 'Nesto'], 'slug' => 'nesto']);
    $other->save();
    RegularPrice::create(['product_variant_id' => $this->riceFiveKg, 'chain_id' => $other->id, 'price' => '52.00', 'observed_at' => '2026-10-10', 'source' => 'staff']);
    RegularPrice::create(['product_variant_id' => $this->riceFiveKg, 'chain_id' => $other->id, 'price' => '60.00', 'observed_at' => '2026-07-01', 'source' => 'staff']);
    RegularPrice::create(['product_variant_id' => $this->riceFiveKg, 'chain_id' => $this->chain->id, 'price' => '54.50', 'observed_at' => '2026-10-10', 'source' => 'staff']);

    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    app(PublishBrochure::class)($brochure);

    expect(json_decode(searchDocs()[0]['document']['usual_prices'], true))->toBe([[$other->id, 52, '2026-10-10']]);
});

it('finds offers by radius through any of their branches', function () {
    $second = $this->chain->branches()->create(['name' => ['en' => 'Al Nahda'], 'slug' => 'al-nahda', 'location' => Point::makeGeodetic(25.29, 55.37)]);
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    app(PublishBrochure::class)($brochure);

    expect(searchDocs(['filter_by' => "locations:({$second->location->getLatitude()},{$second->location->getLongitude()},2 km)"]))->toHaveCount(1)
        ->and(searchDocs(['filter_by' => 'locations:(24.45,54.37,20 km)']))->toBeEmpty();
});

it('moves an offer in search when its branch moves or closes', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    app(PublishBrochure::class)($brochure);
    $abuDhabi = ['filter_by' => 'locations:(24.45,54.37,20 km)'];
    expect(searchDocs($abuDhabi))->toBeEmpty();

    $this->branch->update(['location' => Point::makeGeodetic(24.45, 54.37)]);
    expect(searchDocs($abuDhabi))->toHaveCount(1);

    $this->branch->update(['is_active' => false]);
    expect(searchDocs($abuDhabi))->toBeEmpty();
});

it('updates offers in search when a branch is deleted', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    app(PublishBrochure::class)($brochure);

    Branch::query()->whereKey($this->branch->id)->first()->delete();

    expect(searchDocs(['filter_by' => "brochure_id:={$brochure->id}"])[0]['document'])->not->toHaveKey('locations');
});

it('copies a chain grade to its offers when staff change it', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    app(PublishBrochure::class)($brochure);

    $this->chain->update(['grade' => 2]);

    expect(searchDocs()[0]['document']['chain_grade'])->toBe(2);
});

it('fills empty product group grades with essentials, and leaves the rest alone', function () {
    $milk = ProductGroup::where('slug', 'fresh-milk')->firstOrFail();
    $rice = ProductGroup::where('slug', 'basmati-rice')->firstOrFail();
    $rice->update(['grade' => 2]);
    $tissue = ProductGroup::create(['category_id' => $milk->category_id, 'name' => ['en' => 'Tissues'], 'slug' => 'tissues', 'comparison_unit_code' => 'pc']);
    $tissue->update(['category_id' => DB::table('categories')->where('slug', 'paper-tissues')->value('id')]);
    $sugar = ProductGroup::create(['category_id' => $tissue->category_id, 'name' => ['en' => 'Brown Sugar'], 'slug' => 'brown-sugar', 'comparison_unit_code' => 'kg']);

    app(EssentialGrades::class)();

    expect($milk->fresh()->grade)->toBe(1)
        ->and($rice->fresh()->grade)->toBe(2)
        ->and($tissue->fresh()->grade)->toBeNull()
        ->and($sugar->fresh()->grade)->toBe(1);
});

it('uses a product grade over its group, then the group, then 3', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    $offer = Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    app(PublishBrochure::class)($brochure);
    expect(searchDocs()[0]['document']['item_grade'])->toBe(3);

    $this->rice->productGroup->update(['grade' => 2]);
    $offer->refresh()->searchable();
    expect(searchDocs()[0]['document']['item_grade'])->toBe(2);

    $this->rice->update(['grade' => 1]);
    $offer->refresh()->searchable();
    expect(searchDocs()[0]['document']['item_grade'])->toBe(1);
});

it('scores rank from grade, popularity and the best deal', function () {
    expect(RankSync::rank(1, 1, 40))->toBe(10000)
        ->and(RankSync::rank(4, 0, 0))->toBe(0)
        ->and(RankSync::rank(1, 0, 0))->toBe(4500)
        ->and(RankSync::rank(4, 0, 90))->toBe(2000)
        ->and(RankSync::rank(4, 0, -30))->toBe(0);
});

it('pushes ranks to every offer of a product, ordered so grouping finds the cheapest offer', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    $tenKg = $this->rice->variants()->where('slug', '10-kg')->value('id');
    $milk = Product::where('slug', 'almarai-full-fat-fresh-milk')->firstOrFail();
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $tenKg, 'price' => '85.00']);
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $milk->variants()->where('slug', '2-l')->value('id'), 'price' => '10.00']);
    app(PublishBrochure::class)($brochure);
    $milk->productGroup->update(['grade' => 1]);

    $result = app(RankSync::class)();

    expect($result)->toBe(['products' => 2, 'offers' => 3]);

    $groups = Offer::search('*')->options([
        'group_by' => 'product_id', 'group_limit' => 1, 'sort_by' => 'product_rank:desc,effective_unit_price:asc',
    ])->raw()['grouped_hits'];

    expect(collect($groups)->pluck('hits.0.document.product_slug')->all())->toBe(['almarai-full-fat-fresh-milk', 'india-gate-classic-basmati-rice'])
        ->and($groups[1]['hits'][0]['document']['variant_slug'])->toBe('10-kg');
});

it('leaves offers alone when nothing changed, and counts views as popularity', function () {
    $brochure = brochure($this->chain, 'this-week', '2026-10-23', '2026-10-29');
    $milk = Product::where('slug', 'almarai-full-fat-fresh-milk')->firstOrFail();
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->riceFiveKg, 'price' => '45.95']);
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $milk->variants()->where('slug', '2-l')->value('id'), 'price' => '10.00']);
    app(PublishBrochure::class)($brochure);

    app(RankSync::class)();
    expect(app(RankSync::class)()['offers'])->toBe(0);

    app(Counters::class)->add([['kind' => 'product', 'id' => $milk->id, 'type' => 'view']]);

    expect(app(RankSync::class)()['offers'])->toBe(1)
        ->and(ProductStat::find($milk->id))->popularity->toBe(1.0)->views_14d->toBe(1)
        ->and(ProductStat::find($this->rice->id))->popularity->toBe(0.0);
});

it('counts anonymous events sent as text/plain, the way sendBeacon does', function () {
    $body = json_encode(['events' => [
        ['kind' => 'product', 'id' => 7, 'type' => 'view'],
        ['kind' => 'product', 'id' => 7, 'type' => 'click'],
        ['kind' => 'chain', 'id' => 3, 'type' => 'directions'],
    ], 'region' => 5]);

    $response = call('POST', '/api/v1/events', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    call('POST', '/api/v1/events', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);

    $response->assertNoContent();
    $product = DB::table('engagement_daily')->where(['kind' => 'product', 'target_id' => 7])->first();
    expect($product)->day->toBe('2026-10-24')->region_id->toBe(5)->views->toBe(2)->clicks->toBe(2)
        ->and(DB::table('engagement_daily')->where(['kind' => 'chain', 'target_id' => 3])->value('directions'))->toBe(2);
});

it('stays anonymous: no session and no cookie for an event', function () {
    $response = postJson('/api/v1/events', ['events' => [['kind' => 'product', 'id' => 7, 'type' => 'view']]], ['Origin' => 'http://localhost:4321']);

    $response->assertNoContent();
    expect($response->headers->getCookies())->toBeEmpty();
});

it('rejects unknown events', function () {
    postJson('/api/v1/events', ['events' => [['kind' => 'user', 'id' => 7, 'type' => 'view']]])->assertUnprocessable();
    postJson('/api/v1/events', ['events' => array_fill(0, 51, ['kind' => 'product', 'id' => 7, 'type' => 'view'])])->assertUnprocessable();
});

it('forgets counts after the retention period', function () {
    app(Counters::class)->add([['kind' => 'product', 'id' => 7, 'type' => 'view']]);
    Carbon::setTestNow(now()->addDays(Counters::KEEP_DAYS + 1));

    expect(app(Counters::class)->prune())->toBe(1);
});

it('reads settings over the defaults, and drops a setting saved back to its default', function () {
    expect(HomeSettings::get('radius_default'))->toBe(10);

    HomeSettings::save(['radius_default' => 25, 'unknown' => 1]);
    expect(HomeSettings::get('radius_default'))->toBe(25);

    HomeSettings::save(['radius_default' => 10]);
    expect(HomeSettings::get('radius_default'))->toBe(10)
        ->and(DB::table('site_settings')->count())->toBe(0);
});
