<?php

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\Product;
use App\Domain\Offers\Offer;
use App\Domain\Offers\SyncTimedOffers;
use App\Domain\Retail\Chain;
use App\Filament\Imports\OfferImporter;
use App\Filament\Imports\OfferImportGuide;
use App\Filament\Resources\Offers\Pages\CreateOffer;
use App\Models\User;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();
    $this->lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();
    $this->rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail()->variants()->where('slug', '5-kg')->firstOrFail();
    $this->at = fn (string $time) => Carbon::setTestNow(Carbon::parse($time, 'Asia/Dubai'));
    $this->found = fn () => Offer::search('basmati')->raw()['found'];
});

afterEach(fn () => Offer::removeAllFromSearch());

it('shows a morning offer only from 8 to 11 on each of its days, counting down to 11', function () {
    ($this->at)('2026-10-24 07:30');
    $brochure = Brochure::create(['chain_id' => $this->lulu->id, 'title' => ['en' => 'Mornings'], 'slug' => 'lulu-mornings', 'valid_from' => '2026-10-24', 'valid_to' => '2026-10-26']);
    $offer = Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $this->rice->id, 'price' => '39.95', 'start_time' => '08:00', 'end_time' => '11:00']);
    app(PublishBrochure::class)($brochure);

    // Published before it starts: not shown yet, not even on the product page.
    expect(($this->found)())->toBe(0);
    getJson('/api/v1/products/india-gate-classic-basmati-rice')->assertJsonPath('variants.0.offers', []);

    ($this->at)('2026-10-24 08:01');
    app(SyncTimedOffers::class)();
    expect(Offer::search('basmati')->raw()['hits'][0]['document'])
        ->time_window->toBe('8:00 AM – 11:00 AM')
        ->window_ends_at->toBe(Carbon::parse('2026-10-24 11:00', 'Asia/Dubai')->getTimestamp());
    $api = collect(getJson('/api/v1/products/india-gate-classic-basmati-rice')->json('variants'))->flatMap(fn ($v) => $v['offers']);
    expect($api->firstWhere('id', $offer->id))->time_window->toBe('8:00 AM – 11:00 AM');

    ($this->at)('2026-10-24 11:02');
    app(SyncTimedOffers::class)();
    expect(($this->found)())->toBe(0);

    // The next morning it comes back, counting down to that day's 11:00.
    ($this->at)('2026-10-25 08:04');
    app(SyncTimedOffers::class)();
    expect(Offer::search('basmati')->raw()['hits'][0]['document']['window_ends_at'])
        ->toBe(Carbon::parse('2026-10-25 11:00', 'Asia/Dubai')->getTimestamp());
});

it('takes the times on the Offers page, and none for an all-day offer', function () {
    ($this->at)('2026-10-24 09:00');
    actingAs(User::where('email', 'admin@offer.test')->firstOrFail(), 'admin');
    $form = ['chain_id' => $this->lulu->id, 'product_variant_id' => $this->rice->id, 'price' => '39.95', 'promo_type' => 'price', 'valid_from' => '2026-10-24', 'valid_to' => '2026-10-24'];

    livewire(CreateOffer::class)->fillForm([...$form, 'timed' => true, 'start_time' => '11:00', 'end_time' => '08:00'])->call('create')->assertHasFormErrors(['end_time']);
    livewire(CreateOffer::class)->fillForm([...$form, 'timed' => true, 'start_time' => '08:00', 'end_time' => '11:00'])->call('create')->assertHasNoFormErrors();

    $offer = Offer::sole();
    expect($offer->isTimed())->toBeTrue()->and($offer->isOnNow())->toBeTrue()->and(($this->found)())->toBe(1);

    // Switching the times off makes it an all-day offer again.
    livewire(CreateOffer::class)->fillForm([...$form, 'price' => '38.00', 'timed' => true, 'start_time' => '08:00', 'end_time' => '11:00'])
        ->fillForm(['timed' => false])->call('create')->assertHasNoFormErrors();
    expect(Offer::latest('id')->first()->isTimed())->toBeFalse();
});

it('reads times from a CSV as 08:00 or 8 AM', function () {
    ($this->at)('2026-10-24 09:00');
    $user = User::where('email', 'admin@offer.test')->firstOrFail();
    $import = Import::create(['user_id' => $user->id, 'file_name' => 'offers.csv', 'file_path' => 'offers.csv', 'importer' => OfferImporter::class, 'total_rows' => 2]);
    $columns = array_combine(array_keys(OfferImportGuide::COLUMNS), array_keys(OfferImportGuide::COLUMNS));
    $row = fn (array $values) => (new OfferImporter($import, $columns, []))([
        'store' => 'lulu-hypermarket', 'product' => 'india-gate-classic-basmati-rice', 'pack' => '5-kg', 'price' => '39.95', 'until' => '2026-10-24', ...$values,
    ]);

    $row(['from_time' => '8 AM', 'until_time' => '11:00']);
    expect(Offer::sole())->start_time->toBe('08:00:00')->end_time->toBe('11:00:00');

    expect(fn () => $row(['from_time' => '11:00', 'until_time' => '8:00 AM']))->toThrow(RowImportFailedException::class, 'later than');
});
