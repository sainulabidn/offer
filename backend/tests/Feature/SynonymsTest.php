<?php

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Product;
use App\Domain\Offers\Offer;
use App\Domain\Retail\Chain;
use App\Domain\Search\Synonym;
use App\Domain\Search\Synonyms;
use App\Filament\Resources\Synonyms\Pages\CreateSynonym;
use App\Filament\Resources\Synonyms\SynonymResource;
use App\Models\User;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\SynonymSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Typesense\Client;

// Runs against the real Typesense container: the test_offers collection and the test_grocery synonym set.
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dubai'));
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();

    $brochure = Brochure::create([
        'chain_id' => Chain::firstOrFail()->id,
        'title' => ['en' => 'Weekly deals'],
        'slug' => 'lulu-weekly',
        'valid_from' => '2026-10-23',
        'valid_to' => '2026-10-29',
    ]);
    $rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    Offer::create([
        'brochure_id' => $brochure->id,
        'product_variant_id' => $rice->variants()->where('slug', '5-kg')->value('id'),
        'price' => '45.95',
    ]);
    app(PublishBrochure::class)($brochure);
});

afterEach(function () {
    Offer::removeAllFromSearch();
    try {
        app(Client::class)->synonymSets[Synonyms::setName()]->delete();
    } catch (Throwable) {
    }
});

$found = fn (string $q) => Offer::search($q)->raw()['found'];

it('finds offers by a synonym as soon as it is saved, and stops when it is switched off', function () use ($found) {
    expect($found('chawal'))->toBe(0);

    $synonym = Synonym::create(['terms' => ['  Chawal ', 'rice', 'rice', 'أرز']]);

    expect($synonym->terms)->toBe(['chawal', 'rice', 'أرز'])
        ->and($found('chawal'))->toBe(1)
        ->and($found('أرز'))->toBe(1);

    $synonym->update(['is_active' => false]);
    expect($found('chawal'))->toBe(0);
});

it('supports one-way synonyms', function () use ($found) {
    Synonym::create(['root' => 'grains', 'terms' => ['rice']]);
    expect($found('grains'))->toBe(1);

    Synonym::create(['root' => 'rice', 'terms' => ['quinoa']]);
    expect($found('quinoa'))->toBe(0); // one-way: searching the term doesn't find the root
});

it('uses brand spellings as synonyms', function () use ($found) {
    Brand::where('slug', 'india-gate')->firstOrFail()->update(['synonyms' => ['IG']]);

    expect($found('ig'))->toBe(1);
});

it('links the synonym set again after the collection is rebuilt', function () use ($found) {
    Synonym::create(['terms' => ['chawal', 'rice']]);
    Offer::removeAllFromSearch();
    Offer::query()->live()->get()->searchable();
    expect($found('chawal'))->toBe(0);

    app(Synonyms::class)->sync();

    expect($found('chawal'))->toBe(1);
});

it('seeds the starter list once', function () {
    $this->seed(SynonymSeeder::class);
    $this->seed(SynonymSeeder::class);

    expect(Synonym::count())->toBe(count(SynonymSeeder::GROUPS))
        ->and(app(Client::class)->synonymSets[Synonyms::setName()]->retrieve()['items'])->toHaveCount(count(SynonymSeeder::GROUPS));
});

it('lets staff add synonyms in the admin', function () use ($found) {
    $this->seed(StaffSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole('data_operator'), 'admin');

    Livewire::test(CreateSynonym::class)
        ->fillForm(['terms' => ['chawal'], 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['terms' => 'min']);

    Livewire::test(CreateSynonym::class)
        ->fillForm(['terms' => ['chawal', 'rice'], 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($found('chawal'))->toBe(1);
    $this->get(SynonymResource::getUrl('index'))->assertOk()->assertSee('chawal');
});
