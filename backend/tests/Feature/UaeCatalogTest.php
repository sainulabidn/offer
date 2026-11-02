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
        ->and(app(ImportUaeCatalog::class)())->toBeNull();

    Bus::assertDispatched(GenerateImageSizes::class);
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
