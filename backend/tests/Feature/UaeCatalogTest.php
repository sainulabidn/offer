<?php

use App\Domain\Catalog\Category;
use App\Domain\Catalog\CompareAs;
use App\Domain\Catalog\GenerateImageSizes;
use App\Domain\Catalog\ImportUaeCatalog;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Search\Synonym;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([UnitSeeder::class, CatalogStructureSeeder::class]);
    Storage::fake(config('filesystems.media_disk'));
    Bus::fake([GenerateImageSizes::class]);
});

it('adds the starter catalogue with sizes, origins, grades, synonyms and photos, once', function () {
    $result = app(ImportUaeCatalog::class)();

    $tomato = Product::where('slug', 'tomato')->firstOrFail();
    $group = $tomato->productGroup;
    $ferrero = Product::where('slug', 'ferrero-rocher')->firstOrFail();

    expect($result['products'])->toBeGreaterThan(150)
        ->and($result['photos'])->toBeGreaterThan(20)
        ->and(Category::where('slug', 'pasta-noodles')->exists())->toBeTrue()
        ->and($tomato->variants()->with('optionValues')->get()->map(fn ($v) => $v->optionValues->first()->label)->sort()->values()->all())->toBe(['Jordan', 'Oman', 'UAE'])
        ->and($tomato->variants()->first()->attributes['origin'])->toBeIn(['JO', 'AE', 'OM'])
        ->and($group->compare_as)->toBe(CompareAs::Group)
        ->and($group->grade)->toBe(1)
        ->and($group->getTranslation('name', 'ar'))->toBe('طماطم')
        ->and($ferrero->productGroup->compare_as)->toBe(CompareAs::Product)
        ->and($ferrero->brand->name)->toBe('Ferrero Rocher')
        ->and($ferrero->variants()->count())->toBe(3)
        ->and(Synonym::query()->whereJsonContains('terms', 'hammour')->exists())->toBeTrue()
        ->and(Product::where('slug', 'banana')->firstOrFail()->images()->count())->toBe(1)
        ->and(Product::where('slug', 'tomato')->firstOrFail()->images()->sole()->credit)->toBe('Dmitry Makeev, CC BY-SA 4.0')
        ->and(app(ImportUaeCatalog::class)())->toBeNull();

    Bus::assertDispatched(GenerateImageSizes::class);
});

it('swaps a photo an earlier import added for the new one, and keeps one staff uploaded', function () {
    app(ImportUaeCatalog::class)();
    $banana = Product::where('slug', 'banana')->firstOrFail();
    $banana->images()->update(['path' => 'products/banana-00000000-350.webp']);
    $carrot = Product::where('slug', 'carrot')->firstOrFail();
    $carrot->images()->update(['path' => 'products/01jstaffupload-350.webp']);
    // White onion's photo was taken out of the set.
    $onion = Product::where('slug', 'white-onion')->firstOrFail();
    $onion->images()->create(['path' => 'products/white-onion-12345678-350.webp']);

    app(ImportUaeCatalog::class)(force: true);

    expect($banana->images()->sole()->path)->toMatch('#^products/banana-[0-9a-f]{8}\.jpg$#')->not->toContain('00000000')
        ->and($carrot->images()->sole()->path)->toBe('products/01jstaffupload-350.webp')
        ->and($onion->images()->count())->toBe(0);
});

it('leaves products, groups and grades staff already have as they are', function () {
    $this->seed([GeoSeeder::class, DemoCatalogSeeder::class]);
    $milk = Product::where('slug', 'almarai-full-fat-fresh-milk')->firstOrFail();
    $variants = $milk->variants()->count();
    ProductGroup::where('slug', 'fresh-milk')->update(['grade' => 3]);

    app(ImportUaeCatalog::class)();

    expect($milk->variants()->count())->toBe($variants)
        ->and(ProductGroup::where('slug', 'fresh-milk')->value('grade'))->toBe(3)
        ->and(Product::where('slug', 'almarai-low-fat-fresh-milk')->exists())->toBeTrue();
});
