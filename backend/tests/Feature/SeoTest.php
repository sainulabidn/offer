<?php

use App\Domain\Geo\Region;
use App\Domain\Offers\Offer;
use App\Domain\Seo\SeoPage;
use App\Domain\Seo\SeoSettings;
use App\Filament\Pages\ManageSeoSettings;
use App\Filament\Resources\SeoPages\Pages\CreateSeoPage;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoOffersSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\UnitSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class, DemoOffersSeeder::class, AccessSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

afterEach(fn () => Offer::removeAllFromSearch());

it('cleans paths and keeps filters only on landing pages', function () {
    $landing = SeoPage::create(['path' => 'https://offer.test/Deals/Rice-Dubai/', 'filters' => ['q' => ' rice ', 'brand_ids' => ['3', '', '3'], 'card_only' => '0', 'sort' => 'bogus']]);
    $override = SeoPage::create(['path' => 'Dubai/', 'filters' => ['q' => 'ignored']]);

    expect($landing->path)->toBe('/deals/rice-dubai')
        ->and($landing->filters)->toBe(['q' => 'rice', 'brand_ids' => [3]])
        ->and($override->path)->toBe('/dubai')
        ->and($override->filters)->toBe([]);
});

it('accepts only clean paths', function (string $path, bool $valid) {
    expect((bool) preg_match(SeoPage::PATH_PATTERN, $path))->toBe($valid);
})->with([
    ['/', true],
    ['/dubai/al-barsha/c/dairy-eggs', true],
    ['/deals/rice-2026', true],
    ['/dubai/', false],
    ['/Dubai', false],
    ['/deals/rice--dubai', false],
    ['/deals/rice offers', false],
]);

it('gives the website its settings and the active SEO pages', function () {
    SeoPage::create(['path' => '/deals/rice', 'title' => ['en' => 'Rice offers', 'ar' => 'عروض الأرز'], 'filters' => ['q' => 'rice']]);
    SeoPage::create(['path' => '/dubai', 'description' => ['en' => 'Dubai deals'], 'is_active' => false]);
    SeoSettings::save(['site_name' => 'Offer UAE', 'x_handle' => 'offerae', 'social_profiles' => []]);

    getJson('/api/v1/seo')
        ->assertOk()
        ->assertHeader('Cache-Control')
        ->assertJsonPath('settings.site_name', 'Offer UAE')
        ->assertJsonPath('settings.x_handle', 'offerae')
        ->assertJsonPath('settings.share_image', null)
        ->assertJsonCount(1, 'pages')
        ->assertJsonPath('pages.0.path', '/deals/rice')
        ->assertJsonPath('pages.0.title.ar', 'عروض الأرز')
        ->assertJsonPath('pages.0.filters.q', 'rice');

    // Saving a page reaches the website without waiting for the cache.
    SeoPage::where('path', '/dubai')->update(['is_active' => true]);
    SeoPage::where('path', '/dubai')->first()->touch();
    getJson('/api/v1/seo')->assertJsonCount(2, 'pages')->assertJsonPath('pages.1.filters', null);
});

it('keeps only the code of a pasted verification tag', function () {
    expect(ManageSeoSettings::verificationCode('<meta name="google-site-verification" content="abc123" />'))->toBe('abc123')
        ->and(ManageSeoSettings::verificationCode(' xyz '))->toBe('xyz')
        ->and(ManageSeoSettings::verificationCode(''))->toBeNull();
});

it('indexes the regions of an offer\'s branches and the product\'s pack count', function () {
    $hit = Offer::search('basmati')->raw()['hits'][0]['document'];
    $area = Region::where('slug', 'al-barsha')->firstOrFail();

    expect($hit['region_ids'])->toContain($area->id, $area->parent_id)
        ->and($hit['pack_count'])->toBe(3)
        ->and(Offer::search('*')->options(['filter_by' => "region_ids:={$area->parent_id}"])->raw()['found'])->toBeGreaterThan(0);
});

it('lists areas with their emirate in the directory', function () {
    $area = collect(getJson('/api/v1/directory')->json('areas'))->firstWhere('slug', 'al-barsha');
    $dubai = Region::where('slug', 'dubai')->value('id');

    expect($area)->type->toBe('area')->parent_id->toBe($dubai);
});

it('lists brands with products on the website', function () {
    $brands = collect(getJson('/api/v1/brands')->assertOk()->json());

    expect($brands->pluck('slug'))->toContain('india-gate')
        ->and($brands->first())->toHaveKeys(['id', 'name', 'slug', 'logo', 'private_label']);
});

it('gives the sitemap pack pages and branches', function () {
    $response = getJson('/api/v1/sitemap')->assertOk();
    $rice = collect($response->json('products'))->firstWhere('slug', 'india-gate-classic-basmati-rice');

    expect(collect($rice['variants'])->pluck('slug')->all())->toBe(['1-kg', '5-kg', '10-kg'])
        ->and($response->json('branches'))->toContain('lulu-hypermarket/al-barsha');
});

it('draws a share picture for a product, a pack and a place', function (string $query) {
    $response = get("/api/v1/og/product/india-gate-classic-basmati-rice.jpg{$query}")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    [$width, $height] = getimagesizefromstring($response->getContent());
    expect([$width, $height])->toBe([1200, 630])
        ->and(strlen($response->getContent()))->toBeLessThan(300_000);
})->with([
    'product' => [''],
    'pack' => ['?pack=5-kg'],
    'pack in an emirate' => ['?pack=5-kg&region=dubai'],
    'area with no offers' => ['?region=al-majaz'],
]);

it('draws a share picture for a list and a landing page', function () {
    SeoPage::create(['path' => '/deals/rice', 'heading' => ['en' => 'Cheapest rice'], 'filters' => ['q' => 'rice']]);

    foreach (['?region=dubai&category=pantry', '?brand=india-gate', '?deal=rice', ''] as $query) {
        get("/api/v1/og/list.jpg{$query}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }
});

it('draws nothing for unknown products, places or landing pages', function (string $url) {
    get($url)->assertNotFound();
})->with([
    ['/api/v1/og/product/no-such-product.jpg'],
    ['/api/v1/og/product/india-gate-classic-basmati-rice.jpg?pack=99-kg'],
    ['/api/v1/og/list.jpg?region=atlantis'],
    ['/api/v1/og/list.jpg?deal=missing'],
]);

it('renders the SEO admin screens and creates a landing page', function () {
    actingAs($this->admin, 'admin');
    $dubai = Region::where('slug', 'dubai')->value('id');

    get('/admin/seo-pages')->assertOk();
    get('/admin/seo-pages/create')->assertOk();
    get('/admin/manage-seo-settings')->assertOk();

    livewire(CreateSeoPage::class)
        ->fillForm(['path' => '/deals/rice-dubai', 'title.en' => 'Rice in Dubai', 'filters.q' => 'rice', 'filters.region_id' => $dubai])
        ->call('create')
        ->assertHasNoFormErrors();

    $page = SeoPage::where('path', '/deals/rice-dubai')->firstOrFail();
    expect($page->filters)->toBe(['q' => 'rice', 'region_id' => $dubai]);
    get("/admin/seo-pages/{$page->id}/edit")->assertOk();
});

it('rejects an address with capitals or spaces', function () {
    actingAs($this->admin, 'admin');

    livewire(CreateSeoPage::class)
        ->fillForm(['path' => '/deals/rice offers'])
        ->call('create')
        ->assertHasFormErrors(['path' => 'regex']);
});

it('keeps the SEO screens from staff without the permission', function () {
    $operator = User::factory()->create();
    $operator->assignRole('data_operator');
    actingAs($operator, 'admin');

    get('/admin/seo-pages')->assertForbidden();
    get('/admin/manage-seo-settings')->assertForbidden();
});
