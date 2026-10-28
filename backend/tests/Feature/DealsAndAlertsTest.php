<?php

use App\Domain\Alerts\Alert;
use App\Domain\Alerts\AlertMatcher;
use App\Domain\Alerts\WeeklyDigest;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\Product;
use App\Domain\Engagement\Like;
use App\Domain\Offers\Offer;
use App\Domain\Retail\Chain;
use App\Models\User;
use App\Notifications\WatchlistOffers;
use App\Notifications\WeeklyDigestMail;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dubai'));
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class]);
    $this->chain = Chain::firstOrFail();
    $this->rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    $this->fiveKg = $this->rice->variants()->where('slug', '5-kg')->firstOrFail();

    // Publishes a one-offer brochure for the 5 kg rice.
    $this->publish = function (string $from, string $to, string $price, ?string $regular = null): Offer {
        $brochure = Brochure::create(['chain_id' => $this->chain->id, 'title' => ['en' => "Deals {$from}"], 'slug' => "deals-{$from}", 'valid_from' => $from, 'valid_to' => $to]);
        $offer = Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->fiveKg->id, 'price' => $price, 'regular_price' => $regular]);
        app(PublishBrochure::class)($brochure);

        return $offer->fresh();
    };
});

afterEach(fn () => Offer::removeAllFromSearch());

it('scores a deal against the last 90 days and flags an inflated was-price', function () {
    Notification::fake();
    ($this->publish)('2026-08-01', '2026-08-07', '50.00', '52.00');
    ($this->publish)('2026-09-01', '2026-09-07', '48.00');
    $offer = ($this->publish)('2026-10-23', '2026-10-29', '45.95', '70.00');

    // Median of 10.00 and 9.60 per kg is 9.80; 9.19 is 6.22% below it and the lowest seen.
    expect((float) $offer->deal_score)->toBe(6.22)
        ->and($offer->is_lowest_90d)->toBeTrue()
        // 70.00 was never charged: the highest real price was 52.00.
        ->and($offer->was_price_suspicious)->toBeTrue();
});

it('emails alert owners once, honouring price and radius conditions', function () {
    Notification::fake();
    $near = User::factory()->create();
    $far = User::factory()->create();
    $pricey = User::factory()->create();
    // The demo branch is in Al Barsha.
    Alert::create(['user_id' => $near->id, 'target_type' => 'group', 'target_id' => $this->rice->product_group_id, 'location' => Point::makeGeodetic(25.11, 55.20), 'radius_km' => 5]);
    Alert::create(['user_id' => $far->id, 'target_type' => 'product', 'target_id' => $this->rice->id, 'location' => Point::makeGeodetic(25.35, 55.42), 'radius_km' => 5]);
    Alert::create(['user_id' => $pricey->id, 'target_type' => 'variant', 'target_id' => $this->fiveKg->id, 'max_unit_price' => 8]);

    $offer = ($this->publish)('2026-10-23', '2026-10-29', '45.95');

    Notification::assertSentTo($near, WatchlistOffers::class, fn ($n) => $n->offers->pluck('id')->all() === [$offer->id]);
    Notification::assertNotSentTo([$far, $pricey], WatchlistOffers::class);

    // Matching the same brochure again sends nothing new.
    Notification::fake();
    app(AlertMatcher::class)->forBrochure($offer->brochure);
    Notification::assertNothingSent();
});

it('treats a liked product as an alert and sends the weekly digest', function () {
    Notification::fake();
    $fan = User::factory()->create();
    Like::create(['user_id' => $fan->id, 'likeable_type' => 'product', 'likeable_id' => $this->rice->id]);

    ($this->publish)('2026-10-23', '2026-10-29', '45.95');
    Notification::assertSentTo($fan, WatchlistOffers::class);

    expect(app(WeeklyDigest::class)->send())->toBe(1);
    Notification::assertSentTo($fan, WeeklyDigestMail::class);
});

it('lets a shopper manage alerts through the API', function () {
    $user = User::factory()->create();
    actingAs($user);

    $id = postJson('/api/v1/alerts', ['type' => 'group', 'id' => $this->rice->product_group_id, 'max_unit_price' => 9.5, 'lat' => 25.11, 'lng' => 55.2, 'radius_km' => 10, 'place' => 'Al Barsha'])
        ->assertCreated()->assertJsonPath('name', 'Any Basmati Rice')->json('id');
    getJson('/api/v1/alerts')->assertJsonCount(1)->assertJsonPath('0.place', 'Al Barsha');

    Pest\Laravel\deleteJson("/api/v1/alerts/{$id}")->assertOk();
    expect(Alert::count())->toBe(0);
});

it('returns price history per store', function () {
    Notification::fake();
    ($this->publish)('2026-09-01', '2026-09-07', '48.00');
    ($this->publish)('2026-10-23', '2026-10-29', '45.95');

    getJson('/api/v1/products/india-gate-classic-basmati-rice/history')
        ->assertOk()
        ->assertJsonPath("{$this->fiveKg->id}.0.chain", 'Lulu Hypermarket')
        ->assertJsonPath("{$this->fiveKg->id}.0.points.1.price", 45.95);
});
