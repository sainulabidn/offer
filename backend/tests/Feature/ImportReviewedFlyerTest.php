<?php

use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\GenerateImageSizes;
use App\Domain\Catalog\Product;
use App\Domain\Flyers\ImportReviewedFlyer;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Retail\Chain;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FlyerPdf;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dubai'));
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();
    Storage::fake('local');
    Storage::fake(config('filesystems.media_disk'));
    $this->lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();

    $this->dir = sys_get_temp_dir().'/reviewed-'.uniqid();
    mkdir($this->dir);
    file_put_contents("{$this->dir}/flyer.pdf", FlyerPdf::pictures(['Page one']));
    $this->manifest = function (array $items, array $overrides = []): string {
        file_put_contents("{$this->dir}/manifest.json", json_encode([
            'chain' => 'lulu-hypermarket', 'title' => 'Lulu weekend', 'valid_from' => '2026-10-24', 'valid_to' => '2026-10-30',
            'files' => ['flyer.pdf'], 'pages' => [['page' => 1, 'items' => $items]], ...$overrides,
        ]));

        return "{$this->dir}/manifest.json";
    };
});

afterEach(fn () => Offer::removeAllFromSearch());

it('imports a reviewed flyer: matches known packs, creates missing products with a cropped photo, and publishes', function () {
    $path = ($this->manifest)([
        ['name' => 'India Gate Classic Basmati Rice', 'brand' => 'India Gate', 'size' => '5 kg', 'price' => 39.95, 'was' => 45.95, 'promo' => 'was_now', 'box' => [0, 0, 500, 500], 'sure' => 0.95],
        ['name' => 'Samsung Galaxy A17', 'brand' => 'Samsung', 'size' => '1 pc', 'price' => 699, 'was' => 649, 'promo' => 'was_now', 'box' => [500, 500, 1000, 1000], 'sure' => 0.9,
            'product' => 'Samsung Galaxy A17 128GB', 'category' => 'Electronics > Mobile Phones', 'group' => 'Smartphones'],
        ['name' => 'Nameless', 'price' => 0],
    ]);

    $stats = app(ImportReviewedFlyer::class)($path, null, fn () => null);

    $brochure = $stats['brochure']->refresh();
    expect($stats['items'])->toBe(2)
        ->and($stats['accepted'])->toBe(2)
        ->and($stats['created'])->toBe(1)
        ->and($brochure->status)->toBe(BrochureStatus::Published)
        ->and($brochure->source)->toBe(ImportReviewedFlyer::SOURCE)
        ->and($brochure->items->pluck('status')->unique()->all())->toBe([ItemStatus::Accepted])
        ->and($brochure->offers()->where('status', OfferStatus::Published)->count())->toBe(2);

    $phones = Category::where('slug', 'mobile-phones')->firstOrFail();
    expect($phones->parent->slug)->toBe('electronics');
    $phone = Product::where('slug', 'samsung-galaxy-a17-128gb')->with('images', 'brand')->firstOrFail();
    expect($phone->category_id)->toBe($phones->id)
        ->and($phone->brand->slug)->toBe('samsung')
        ->and($phone->images)->toHaveCount(1)
        ->and($phone->images[0]->path)->toEndWith('-350.webp');
    Storage::disk(config('filesystems.media_disk'))->assertExists($phone->images[0]->path);
    // A "was" below the price is a misread and is dropped.
    expect(Offer::where('product_variant_id', $phone->variants[0]->id)->value('regular_price'))->toBeNull();

    // The same file again is recognised, not imported twice.
    expect(app(ImportReviewedFlyer::class)($path, null, fn () => null)['skipped'])->toContain("brochure {$brochure->id}");
});

it('does not let one "1 pc" product match another that only looks alike', function () {
    $fridge = fn (string $name, string $brand, float $price, array $box) => ['name' => $name, 'brand' => $brand, 'size' => '1 pc', 'price' => $price, 'promo' => 'price', 'box' => $box, 'sure' => 0.9,
        'category' => 'Electronics & Appliances > Large Appliances', 'group' => 'Refrigerators'];
    $path = ($this->manifest)([
        $fridge('Toshiba Double Door Refrigerator GR-RT559 411L', 'Toshiba', 1599, [0, 0, 500, 500]),
        $fridge('LG Double Door Refrigerator LTB452 342L', 'LG', 1749, [500, 0, 1000, 500]),
        $fridge('Toshiba Double Door Refrigerator GR-RT559 411L', 'Toshiba', 1599, [0, 500, 500, 1000]),
    ]);

    $stats = app(ImportReviewedFlyer::class)($path, null, fn () => null);

    expect($stats['created'])->toBe(2)
        ->and($stats['accepted'])->toBe(3)
        ->and(Product::where('slug', 'lg-double-door-refrigerator-ltb452-342l')->exists())->toBeTrue()
        ->and($stats['brochure']->items()->pluck('product_variant_id')->unique())->toHaveCount(2);
});

it('reports items it cannot place and keeps the rest, from the command line', function () {
    $path = ($this->manifest)([
        ['name' => 'India Gate Classic Basmati Rice', 'brand' => 'India Gate', 'size' => '5 kg', 'price' => 39.95, 'promo' => 'price', 'box' => [0, 0, 500, 500], 'sure' => 0.95],
        ['name' => 'Unknown Gadget', 'size' => '1 pc', 'price' => 20, 'promo' => 'price', 'box' => [500, 500, 1000, 1000], 'sure' => 0.9],
    ]);

    expect(Artisan::call('flyers:import-reviewed', ['manifests' => [$path], '--draft' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('1 accepted')->toContain('Unknown Gadget: no catalog match and no category hint')
        ->and(Offer::where('status', OfferStatus::Draft)->count())->toBe(1);
});
