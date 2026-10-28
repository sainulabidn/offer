<?php

use App\Domain\Catalog\Category;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductVariant;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class]);
});

it('stores each variant quantity in base units', function () {
    $milk = Product::where('slug', 'almarai-full-fat-fresh-milk')->firstOrFail();

    expect($milk->variants->pluck('net_base_qty', 'slug')->all())
        ->toBe(['1-l' => '1000.000', '2-l' => '2000.000', '4-1-l' => '4000.000']);
});

it('allows each option combination only once per product', function () {
    $rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    $fiveKg = $rice->variants()->where('slug', '5-kg')->firstOrFail();

    $duplicate = $rice->variants()->create(['slug' => '5-kg-copy', 'unit_qty' => '5', 'unit_code' => 'kg']);
    $duplicate->syncOptionValues($fiveKg->optionValues->modelKeys());
})->throws(UniqueConstraintViolationException::class);

it('keeps category paths correct when a category moves', function () {
    $pantry = Category::where('slug', 'pantry')->firstOrFail();
    $fresh = Category::where('slug', 'fresh-food')->firstOrFail();
    $rice = Category::where('slug', 'rice-grains')->firstOrFail();
    $basmati = Category::create(['name' => ['en' => 'Basmati'], 'slug' => 'basmati', 'parent_id' => $rice->id]);

    expect($basmati->path)->toBe("/{$pantry->id}/{$rice->id}/");

    $rice->update(['parent_id' => $fresh->id]);

    expect($basmati->fresh()->path)->toBe("/{$fresh->id}/{$rice->id}/")
        ->and(Category::withDescendantsOf($fresh)->pluck('slug'))->toContain('rice-grains', 'basmati');
});

it('finds branches within a radius with PostGIS', function () {
    $within = fn (int $metres) => ProductVariant::query()->getConnection()
        ->table('branches')
        ->whereRaw('ST_DWithin(location, ST_MakePoint(55.3704, 25.2894)::geography, ?)', [$metres])
        ->count();

    // The demo Al Barsha branch is about 26 km from Al Nahda.
    expect($within(20_000))->toBe(0)->and($within(30_000))->toBe(1);
});
