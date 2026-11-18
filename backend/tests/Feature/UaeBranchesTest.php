<?php

use App\Domain\Geo\Country;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ChainFormat;
use App\Domain\Retail\ImportRetailDirectory;
use App\Domain\Retail\ImportUaeBranches;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\GeoSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(GeoSeeder::class);
    Storage::fake(config('filesystems.media_disk'));
    app(ImportRetailDirectory::class)();
});

it('adds the branches with their emirate, name, location and hours, once', function () {
    $result = app(ImportUaeBranches::class)();

    $carrefour = Chain::where('slug', 'carrefour')->firstOrFail();
    $branch = $carrefour->branches()->with('region')->firstOrFail();
    $withHours = Branch::has('hours')->first();

    expect($result['added'])->toBeGreaterThan(700)
        ->and($carrefour->branches()->count())->toBeGreaterThan(30)
        ->and($branch->source_ref)->toStartWith('osm:')
        ->and($branch->region)->not->toBeNull()
        ->and($branch->location->getLatitude())->toBeBetween(22.5, 26.5)
        ->and($withHours->hours()->count())->toBeGreaterThan(0)
        ->and(Branch::query()->selectRaw('chain_id, slug, count(*)')->groupBy('chain_id', 'slug')->havingRaw('count(*) > 1')->count())->toBe(0)
        ->and(app(ImportUaeBranches::class)())->toBeNull();
});

it('keeps branches staff edited, and only fills what they left empty', function () {
    $rows = json_decode(file_get_contents(database_path('data/uae-branches.json')), true)['branches'];
    $row = collect($rows)->first(fn ($r) => $r['phone'] && $r['chain'] === 'sharjah-coop');
    $chain = Chain::where('slug', 'sharjah-coop')->firstOrFail();
    $mine = $chain->branches()->create([
        'name' => ['en' => 'Our name'], 'slug' => 'our-name', 'location' => Point::makeGeodetic($row['lat'], $row['lng']), 'source_ref' => "osm:{$row['osm']}",
    ]);

    app(ImportUaeBranches::class)();

    expect($mine->fresh()->name)->toBe('Our name')
        ->and($mine->fresh()->phone)->toBe($row['phone'])
        ->and(Branch::where('source_ref', "osm:{$row['osm']}")->count())->toBe(1);
});

it('adds the branches kept by hand, and attaches live brochures of stores that had none', function () {
    $hashim = Chain::where('slug', 'hashim-hypermarket')->firstOrFail();
    expect($hashim->branches()->count())->toBe(0);
    $brochure = App\Domain\Brochures\Brochure::create([
        'chain_id' => $hashim->id, 'title' => ['en' => 'Midweek'], 'slug' => 'midweek', 'type' => 'images',
        'valid_from' => now()->toDateString(), 'valid_to' => now()->addDays(3)->toDateString(),
        'status' => App\Domain\Brochures\BrochureStatus::Published,
    ]);

    app(ImportUaeBranches::class)();

    $extra = collect(json_decode(file_get_contents(database_path('data/uae-branches-extra.json')), true)['branches'])->where('chain', 'hashim-hypermarket');
    expect($hashim->branches()->count())->toBe($extra->count())
        ->and($hashim->branches()->first()->source_ref)->toStartWith('extra:hashim-hyperma/')
        ->and($brochure->branches()->count())->toBe($extra->count());
});
