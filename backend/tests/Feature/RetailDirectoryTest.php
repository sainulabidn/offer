<?php

use App\Domain\Geo\Country;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ChainFormat;
use App\Domain\Retail\ImportRetailDirectory;
use App\Domain\Retail\RetailGroup;
use Database\Seeders\GeoSeeder;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->seed(GeoSeeder::class);
    Storage::fake(config('filesystems.media_disk'));
});

it('adds the UAE groups and banners with their logos, once', function () {
    $result = app(ImportRetailDirectory::class)();

    $carrefour = Chain::where('slug', 'carrefour')->firstOrFail();
    $lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();

    expect($result['chains'])->toBeGreaterThan(80)
        ->and($carrefour->retailGroup->name)->toBe('Majid Al Futtaim')
        ->and($carrefour->format)->toBe(ChainFormat::Hypermarket)
        ->and($carrefour->grade)->toBe(1)
        ->and($carrefour->is_verified)->toBeFalse()
        ->and($lulu->logo_path)->toBe('logos/lulu-hypermarket.png')
        ->and(Storage::disk(config('filesystems.media_disk'))->exists('logos/lulu-hypermarket.png'))->toBeTrue()
        ->and(Chain::where('slug', 'mubarak-hypermarket')->value('staff_notes'))->toContain('Needs verification')
        ->and(app(ImportRetailDirectory::class)())->toBeNull();
});

it('only fills gaps in stores staff already set up, and leaves deleted ones deleted', function () {
    $group = RetailGroup::create(['name' => ['en' => 'Lulu'], 'slug' => 'lulu']);
    Chain::create([
        'retail_group_id' => $group->id,
        'country_id' => Country::where('code', 'AE')->value('id'),
        'name' => ['en' => 'Lulu Hypermarket'],
        'slug' => 'lulu-hypermarket',
        'format' => ChainFormat::Hypermarket,
        'grade' => 2,
    ]);

    app(ImportRetailDirectory::class)();
    $lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();

    expect($lulu->name)->toBe('Lulu Hypermarket')
        ->and($lulu->grade)->toBe(2)
        ->and($lulu->retail_group_id)->toBe($group->id)
        ->and($lulu->website)->toBe('https://www.luluhypermarket.com')
        ->and($lulu->logo_path)->toBe('logos/lulu-hypermarket.png');

    Chain::where('slug', 'park-n-shop')->delete();
    app(ImportRetailDirectory::class)();
    expect(Chain::where('slug', 'park-n-shop')->exists())->toBeFalse();
});

it('lists only stores with a branch in the sitemap', function () {
    app(ImportRetailDirectory::class)();
    $chain = Chain::where('slug', 'carrefour')->firstOrFail();
    $chain->branches()->create(['name' => ['en' => 'Deira'], 'slug' => 'deira', 'location' => Clickbar\Magellan\Data\Geometries\Point::makeGeodetic(25.27, 55.31)]);

    expect(getJson('/api/v1/sitemap')->json('chains'))->toBe(['carrefour']);
});
