<?php

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\Product;
use App\Domain\Offers\ExpireOffers;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Retail\Chain;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;

// Runs against the real Typesense container, in the test_offers collection.
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dubai'));
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();

    $this->brochure = Brochure::create([
        'chain_id' => Chain::firstOrFail()->id,
        'title' => ['en' => 'Weekly deals'],
        'slug' => 'lulu-weekly',
        'valid_from' => '2026-10-23',
        'valid_to' => '2026-10-29',
    ]);
    $rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    $this->offer = Offer::create([
        'brochure_id' => $this->brochure->id,
        'product_variant_id' => $rice->variants()->where('slug', '5-kg')->value('id'),
        'price' => '45.95',
        'regular_price' => '54.50',
    ]);
});

afterEach(fn () => Offer::removeAllFromSearch());

it('keeps drafts out of search and indexes offers when the brochure is published', function () {
    expect(Offer::search('basmati')->raw()['found'])->toBe(0);

    app(PublishBrochure::class)($this->brochure);

    $hit = Offer::search('basmati')->raw()['hits'][0]['document'];
    expect($hit)
        ->product_name->toBe('India Gate Classic Basmati Rice')
        ->variant_label->toBe('5 kg')
        ->chain_name->toBe('Lulu Hypermarket')
        ->price->toBe(45.95)
        ->effective_unit_price->toBe(9.19)
        ->unit_label->toBe('per kg')
        ->discount_pct->toBe(15.69)
        ->origin->toBe('IN');
});

it('indexes multi-buy details for the shopping plan', function () {
    $this->offer->update(['promo_type' => 'multibuy', 'promo_params' => ['qty' => 2, 'total' => 80], 'conditions' => ['max_per_customer' => 4]]);
    app(PublishBrochure::class)($this->brochure);

    expect(Offer::search('basmati')->raw()['hits'][0]['document'])
        ->promo_qty->toBe(2)->promo_total->toEqual(80)->max_per_customer->toBe(4);
});

it('finds offers of a parent category', function () {
    app(PublishBrochure::class)($this->brochure);
    $pantry = Category::where('slug', 'pantry')->value('id');

    $found = Offer::search('*')->options(['filter_by' => "category_ids:={$pantry}"])->raw()['found'];

    expect($found)->toBe(1);
});

it('expires finished offers and removes them from search', function () {
    app(PublishBrochure::class)($this->brochure);

    Carbon::setTestNow(Carbon::parse('2026-10-30 00:05', 'Asia/Dubai'));
    $result = app(ExpireOffers::class)();

    expect($result['offers'])->toBe(1)
        ->and($this->offer->fresh()->status)->toBe(OfferStatus::Expired)
        ->and($this->brochure->fresh()->status)->toBe(BrochureStatus::Expired)
        ->and(Offer::search('basmati')->raw()['found'])->toBe(0);
});
