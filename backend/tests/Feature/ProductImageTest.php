<?php

use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductImage;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class]);
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('products');
});

/** A 1000×800 white photo with a dark 400×200 product in it. */
function photoOnWhite(string $path): void
{
    $photo = imagecreatetruecolor(1000, 800);
    imagefill($photo, 0, 0, imagecolorallocate($photo, 255, 255, 255));
    imagefilledrectangle($photo, 300, 300, 699, 499, imagecolorallocate($photo, 40, 90, 60));
    imagepng($photo, Storage::disk('public')->path($path));
}

it('keeps only a trimmed 350 px square and a 160 px copy, and exposes them to the website', function () {
    photoOnWhite('products/rice.png');
    $product = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();

    $image = $product->images()->create(['path' => 'products/rice.png', 'alt' => ['en' => 'Rice bag']]); // job runs on the sync queue

    expect($image->fresh())->path->toBe('products/rice-350.webp')->width->toBe(350)->height->toBe(350)
        ->and(Storage::disk('public')->allFiles('products'))->toEqualCanonicalizing(['products/rice-160.webp', 'products/rice-350.webp']);

    $square = imagecreatefromwebp(Storage::disk('public')->path('products/rice-350.webp'));
    $dark = fn (int $x, int $y) => (imagecolorat($square, $x, $y) & 0xFF) < 128;
    // The white border is trimmed: the product spans the square, less its margin, and stays centred.
    expect([imagesx($square), imagesy($square)])->toBe([350, 350])
        ->and($dark(30, 175))->toBeTrue()
        ->and($dark(175, 60))->toBeFalse()
        ->and(getimagesize(Storage::disk('public')->path('products/rice-160.webp'))[0])->toBe(160);

    getJson('/api/v1/products/india-gate-classic-basmati-rice')
        ->assertJsonPath('images.0.alt', 'Rice bag')
        ->assertJsonPath('images.0.width', 350)
        ->assertJsonPath('images.0.base', Storage::disk('public')->url('products/rice'));
});

it('deletes every size with the image, including those earlier versions made', function () {
    photoOnWhite('products/a.png');
    $image = Product::firstOrFail()->images()->create(['path' => 'products/a.png']);
    Storage::disk('public')->put('products/a-1200.avif', 'old');

    ProductImage::findOrFail($image->id)->delete();

    expect(Storage::disk('public')->allFiles('products'))->toBe([]);
});
