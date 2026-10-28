<?php

use App\Domain\Offers\Offer;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoOffersSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;

use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class, DemoOffersSeeder::class]);
});

afterEach(fn () => Offer::removeAllFromSearch());

it('returns a product with every store price per variant, cheapest per unit first', function () {
    getJson('/api/v1/products/india-gate-classic-basmati-rice')
        ->assertOk()
        ->assertHeader('Cache-Control')
        ->assertJsonPath('name', 'India Gate Classic Basmati Rice')
        ->assertJsonPath('product_group.unit_label', 'per kg')
        ->assertJsonPath('variants.1.label', '5 kg')
        ->assertJsonPath('variants.1.offers.0.chain.slug', 'lulu-hypermarket')
        ->assertJsonPath('variants.1.offers.0.effective_unit_price', 9.19)
        ->assertJsonPath('variants.0.offers', []);
});

it('hides draft products', function () {
    App\Domain\Catalog\Product::where('slug', 'banana')->update(['status' => 'draft']);

    getJson('/api/v1/products/banana')->assertNotFound();
});

it('returns a chain with branch coordinates', function () {
    getJson('/api/v1/chains/lulu-hypermarket')
        ->assertOk()
        ->assertJsonPath('branches.0.slug', 'al-barsha')
        ->assertJsonPath('branches.0.lat', 25.1135);
});

it('returns the store directory with live brochures per branch', function () {
    $response = getJson('/api/v1/directory')->assertOk();

    $branch = $response->json('branches.0');
    expect($branch)->chain->toBe('Lulu Hypermarket')->lat->toBe(25.1135)
        ->and($response->json('brochures.0.branch_ids'))->toBe([$branch['id']])
        ->and(collect($response->json('areas'))->pluck('name'))->toContain('Al Nahda, Dubai');
});

it('returns the category tree', function () {
    getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonPath('0.slug', 'fresh-food')
        ->assertJsonPath('0.children.0.slug', 'fruits-vegetables');
});

it('hands out a scoped search key that can search but not write', function () {
    config(['services.typesense.search_key' => app(App\Domain\Search\SearchKeys::class)->createSearchOnlyKey()]);
    $key = getJson('/api/v1/search-config')->assertOk()->json('key');

    $search = Http::withHeaders(['X-TYPESENSE-API-KEY' => $key])
        ->get('http://typesense:8108/collections/test_offers/documents/search', ['q' => 'basmati', 'query_by' => 'product_name']);
    $write = Http::withHeaders(['X-TYPESENSE-API-KEY' => $key])
        ->post('http://typesense:8108/collections/test_offers/documents', ['id' => '999']);

    expect($search->json('found'))->toBe(2)
        ->and($write->status())->toBe(401);
});
