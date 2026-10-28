<?php

use App\Domain\Brochures\Brochure;
use App\Domain\Catalog\Product;
use App\Domain\Offers\Offer;
use App\Domain\Offers\PromoType;
use App\Domain\Retail\Chain;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class]);
    $this->brochure = Brochure::create([
        'chain_id' => Chain::firstOrFail()->id,
        'title' => ['en' => 'Weekly deals'],
        'slug' => 'lulu-weekly-test',
        'valid_from' => '2026-10-23',
        'valid_to' => '2026-10-29',
    ]);
    $this->variant = fn (string $product, string $variant) => Product::where('slug', $product)->firstOrFail()
        ->variants()->where('slug', $variant)->firstOrFail();
});

it('derives unit price, discount, chain and dates when an offer is saved', function () {
    $offer = Offer::create([
        'brochure_id' => $this->brochure->id,
        'product_variant_id' => ($this->variant)('india-gate-classic-basmati-rice', '5-kg')->id,
        'price' => '45.95',
        'regular_price' => '54.50',
    ]);

    expect($offer->unit_price)->toBe('9.1900')
        ->and($offer->effective_unit_price)->toBe('9.1900')
        ->and($offer->discount_pct)->toBe('15.69')
        ->and($offer->chain_id)->toBe($this->brochure->chain_id)
        ->and($offer->valid_to->toDateString())->toBe('2026-10-29');
});

it('prices a multi-buy deal per item', function () {
    // "2 × 2 L milk for AED 20": 10.00 each → 5.00 per litre.
    $offer = Offer::create([
        'brochure_id' => $this->brochure->id,
        'product_variant_id' => ($this->variant)('almarai-full-fat-fresh-milk', '2-l')->id,
        'price' => '11.50',
        'regular_price' => '11.50',
        'promo_type' => PromoType::Multibuy,
        'promo_params' => ['qty' => 2, 'total' => 20],
    ]);

    expect($offer->unit_price)->toBe('5.7500')
        ->and($offer->effective_unit_price)->toBe('5.0000')
        ->and($offer->discount_pct)->toBe('13.04');
});
