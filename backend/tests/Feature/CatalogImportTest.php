<?php

use App\Domain\Catalog\CatalogRowImporter;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductVariant;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\UnitSeeder;

beforeEach(function () {
    $this->seed([UnitSeeder::class, CatalogStructureSeeder::class]);
    $this->import = fn (array $row) => app(CatalogRowImporter::class)->import($row);
});

$row = fn (array $overrides = []) => [
    'category' => 'rice-grains',
    'product_group' => 'Basmati Rice',
    'brand' => 'India Gate',
    'product_name' => 'India Gate Classic Basmati Rice',
    'unit_qty' => '5',
    'unit' => 'KGS',
    'gtin' => '8901234567890',
    'origin' => 'in',
    ...$overrides,
];

it('creates group, brand, product, option and variant from one row', function () use ($row) {
    $variant = ($this->import)($row());

    expect($variant->net_base_qty)->toBe('5000.000')
        ->and($variant->unit_code)->toBe('kg')
        ->and($variant->attributes['origin'])->toBe('IN')
        ->and($variant->optionValues->first()->label)->toBe('5 kg')
        ->and($variant->product->productGroup->comparison_unit_code)->toBe('kg')
        ->and($variant->product->brand->name)->toBe('India Gate');
});

it('adds more variants to the same product and updates on re-import', function () use ($row) {
    ($this->import)($row());
    ($this->import)($row(['unit_qty' => '10', 'gtin' => '8901234567891']));
    ($this->import)($row(['product_name_ar' => 'أرز بسمتي'])); // same 5 kg row again

    $product = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    expect($product->variants()->count())->toBe(2)
        ->and($product->options()->count())->toBe(1)
        ->and($product->getTranslation('name', 'ar'))->toBe('أرز بسمتي')
        ->and(ProductVariant::count())->toBe(2);
});

it('handles multipacks and per-100-sheet groups', function () {
    $variant = ($this->import)([
        'category' => 'paper-tissues', 'product_group' => 'Facial Tissues', 'brand' => 'Fine',
        'product_name' => 'Fine Facial Tissues', 'pack_count' => '5', 'unit_qty' => '200', 'unit' => 'sheets',
    ]);

    expect($variant->net_base_qty)->toBe('1000.000')
        ->and($variant->optionValues->first()->label)->toBe('5 × 200 sheet')
        ->and((string) $variant->product->productGroup->comparison_quantity)->toBe('100.000');
});

it('rejects rows it cannot place', function (array $overrides, string $message) use ($row) {
    expect(fn () => ($this->import)($row($overrides)))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown unit' => [['unit' => 'bags'], 'Unknown unit'],
    'unknown category' => [['category' => 'gadgets'], 'Unknown category'],
    'missing quantity' => [['unit_qty' => ''], 'unit_qty'],
]);

it('refuses litres in a group compared per kg', function () use ($row) {
    ($this->import)($row());

    expect(fn () => ($this->import)($row(['product_name' => 'Odd Rice Drink', 'unit' => 'l', 'gtin' => null])))
        ->toThrow(InvalidArgumentException::class, 'does not match');
});
