<?php

use App\Domain\Catalog\GenerateImageSizes;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductImage;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class]);
    Storage::fake('public');
});

it('makes AVIF and WebP sizes and exposes them to the website', function (string $tool) {
    config(['filesystems.image_tool' => $tool]);
    // A real 1000×800 PNG made by libvips itself.
    $path = 'products/rice.png';
    Storage::disk('public')->makeDirectory('products');
    Process::run(['vips', 'black', Storage::disk('public')->path($path), '1000', '800'])->throw();

    $product = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    $image = $product->images()->create(['path' => $path, 'alt' => ['en' => 'Rice bag']]); // job runs on the sync queue

    expect($image->fresh())->width->toBe(1000)->height->toBe(800);
    foreach (GenerateImageSizes::WIDTHS as $width) {
        Storage::disk('public')->assertExists(["products/rice-{$width}.avif", "products/rice-{$width}.webp"]);
    }
    // Never upscaled: the 1200 px copy stays 1000 px wide; smaller sizes really are smaller.
    $widthOf = fn (string $file) => trim(Process::run(['vipsheader', '-f', 'width', Storage::disk('public')->path($file)])->output());
    expect($widthOf('products/rice-1200.webp'))->toBe('1000')
        ->and($widthOf('products/rice-320.avif'))->toBe('320');

    getJson('/api/v1/products/india-gate-classic-basmati-rice')
        ->assertJsonPath('images.0.alt', 'Rice bag')
        ->assertJsonPath('images.0.width', 1000);
})->with(['libvips' => 'vips', 'GD (AlmaLinux 8 server)' => 'gd']);

it('deletes every size with the image', function () {
    Storage::disk('public')->makeDirectory('products');
    Process::run(['vips', 'black', Storage::disk('public')->path('products/a.png'), '400', '400'])->throw();
    $image = Product::firstOrFail()->images()->create(['path' => 'products/a.png']);

    ProductImage::findOrFail($image->id)->delete();

    expect(Storage::disk('public')->allFiles('products'))->toBe([]);
});
