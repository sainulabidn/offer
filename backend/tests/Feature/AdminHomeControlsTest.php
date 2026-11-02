<?php

use App\Domain\Access\Access;
use App\Domain\Access\UserStoreAccess;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\CatalogRowImporter;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Geo\Country;
use App\Domain\Offers\Offer;
use App\Domain\Offers\RegularPrice;
use App\Domain\Offers\ShelfPrices;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ChainFormat;
use App\Domain\Retail\RetailGroup;
use App\Domain\Settings\HomeSettings;
use App\Domain\Shopper\ShopperPreference;
use App\Filament\Imports\CatalogImporter;
use App\Filament\Pages\ManageHomeSettings;
use App\Filament\Pages\MissingShelfPrices;
use App\Filament\Resources\Chains\Pages\EditChain;
use App\Filament\Resources\Chains\Pages\ListChains;
use App\Filament\Resources\ProductGroups\Pages\ListProductGroups;
use App\Filament\Resources\RegularPrices\Pages\CreateRegularPrice;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Auth;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();

    $this->lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();
    $this->group = RetailGroup::create(['name' => ['en' => 'Nesto Group'], 'slug' => 'nesto-group']);
    $this->nesto = Chain::create([
        'retail_group_id' => $this->group->id,
        'country_id' => Country::where('code', 'AE')->value('id'),
        'name' => ['en' => 'Nesto'],
        'slug' => 'nesto',
        'format' => ChainFormat::Hypermarket,
        'grade' => 1,
    ]);
    $this->nesto->branches()->create(['name' => ['en' => 'Al Nahda'], 'slug' => 'al-nahda', 'location' => Point::makeGeodetic(25.29, 55.37)]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->owner = User::factory()->create();
    $this->owner->assignRole('store_owner');
    UserStoreAccess::sync($this->owner, ['retail_group' => [$this->group->id]]);
    $this->operator = User::factory()->create();
    $this->operator->assignRole('data_operator');
});

afterEach(fn () => Offer::removeAllFromSearch());

function signInAdmin(User $user): void
{
    Auth::forgetGuards();
    Access::forget($user->refresh());
    actingAs($user, 'admin');
}

/** A live offer on the 5 kg rice at Lulu, a daily item once its group is graded. */
function dailyRiceOffer(Chain $chain): int
{
    $rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    $rice->productGroup->update(['grade' => 1]);
    $variant = $rice->variants()->where('slug', '5-kg')->value('id');

    $brochure = Brochure::create([
        'chain_id' => $chain->id,
        'title' => ['en' => 'This week'],
        'slug' => "week-{$chain->id}",
        'valid_from' => now('Asia/Dubai')->subDay()->toDateString(),
        'valid_to' => now('Asia/Dubai')->addDays(3)->toDateString(),
    ]);
    Offer::create(['brochure_id' => $brochure->id, 'product_variant_id' => $variant, 'price' => '45.95']);
    app(PublishBrochure::class)($brochure);

    return $variant;
}

it('lets staff with the settings permission set a chain grade', function () {
    signInAdmin($this->admin);

    livewire(ListChains::class)->assertTableColumnVisible('grade');
    livewire(EditChain::class, ['record' => $this->nesto->id])
        ->fillForm(['grade' => 2])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->nesto->fresh()->grade)->toBe(2);
});

it('sets a grade on many product groups at once', function () {
    signInAdmin($this->admin);
    $groups = ProductGroup::query()->get();

    livewire(ListProductGroups::class)
        ->selectTableRecords($groups->modelKeys())
        ->callAction(TestAction::make('setGrade')->table()->bulk(), ['grade' => 1]);

    expect(ProductGroup::where('grade', 1)->count())->toBe($groups->count());
});

it('stops a store owner raising their own chain\'s grade', function () {
    signInAdmin($this->owner);

    livewire(ListChains::class)->assertTableColumnHidden('grade');

    livewire(EditChain::class, ['record' => $this->nesto->id])
        ->assertFormFieldHidden('grade')
        ->fillForm(['grade' => 1, 'website' => 'https://nesto.test'])
        ->call('save');

    expect($this->nesto->fresh())->grade->toBe(1)->website->toBe('https://nesto.test');
    $this->nesto->update(['grade' => 3]);

    livewire(EditChain::class, ['record' => $this->nesto->id])
        ->set('data.grade', 1)
        ->call('save');

    expect($this->nesto->fresh()->grade)->toBe(3);
});

it('takes a grade from a catalog import only from someone with the settings permission', function () {
    $row = ['category' => 'rice-grains', 'product_group' => 'Basmati Rice', 'product_name' => 'India Gate Classic Basmati Rice', 'unit_qty' => '5', 'unit' => 'kg', 'grade' => '1'];
    $importAs = function (User $user) use ($row) {
        $import = new Import(['user_id' => $user->id, 'file_name' => 'a.csv', 'file_path' => 'a.csv', 'importer' => CatalogImporter::class, 'total_rows' => 1]);
        $import->setRelation('user', $user);
        $importer = new CatalogImporter($import, [], []);
        (fn () => $this->data = $row)->call($importer);
        $importer->saveRecord();
    };
    $group = ProductGroup::where('slug', 'basmati-rice')->firstOrFail();

    $importAs($this->owner);
    expect($group->fresh()->grade)->toBeNull();

    $importAs($this->admin);
    expect($group->fresh()->grade)->toBe(1);
});

it('leaves a grade alone when a catalog row has none', function () {
    $group = ProductGroup::where('slug', 'basmati-rice')->firstOrFail();
    $group->update(['grade' => 2]);

    app(CatalogRowImporter::class)->import(['category' => 'rice-grains', 'product_group' => 'Basmati Rice', 'product_name' => 'India Gate Classic Basmati Rice', 'unit_qty' => '5', 'unit' => 'kg']);

    expect($group->fresh()->grade)->toBe(2);
});

it('keeps the settings page to people with the permission', function () {
    signInAdmin($this->admin);
    get(ManageHomeSettings::getUrl())->assertOk();

    signInAdmin($this->owner);
    get(ManageHomeSettings::getUrl())->assertForbidden();
});

it('saves radius settings as numbers, sorted, and leaves unchanged ones out', function () {
    signInAdmin($this->admin);

    livewire(ManageHomeSettings::class)
        ->fillForm(['radius_options' => ['25', '5', '75'], 'radius_default' => 25])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(HomeSettings::get('radius_options'))->toBe([5, 25, 75])
        ->and(HomeSettings::get('radius_default'))->toBe(25)
        ->and(App\Domain\Settings\SiteSetting::query()->pluck('key')->sort()->values()->all())->toBe(['radius_default', 'radius_options']);
});

it('refuses a default distance over the limit', function () {
    signInAdmin($this->admin);

    livewire(ManageHomeSettings::class)
        ->fillForm(['radius_default' => 500, 'radius_max' => 100])
        ->call('save')
        ->assertHasFormErrors(['radius_default']);
});

it('sets a shopper\'s radius from the admin, so their devices pick it up', function () {
    signInAdmin($this->admin);
    $shopper = User::factory()->create();
    ShopperPreference::create(['user_id' => $shopper->id, 'radius_km' => 10, 'client_updated_at' => now()->subDay()]);

    livewire(EditUser::class, ['record' => $shopper->id])
        ->assertFormSet(['shopper_radius_km' => 10])
        ->fillForm(['shopper_radius_km' => 25])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = ShopperPreference::find($shopper->id);
    expect($saved->radius_km)->toBe(25)
        ->and($saved->client_updated_at->isToday())->toBeTrue();
});

it('adds no preferences when staff edit someone without touching their radius', function () {
    signInAdmin($this->admin);
    $shopper = User::factory()->create();

    livewire(EditUser::class, ['record' => $shopper->id])->fillForm(['name' => 'New name'])->call('save')->assertHasNoFormErrors();

    expect(ShopperPreference::find($shopper->id))->toBeNull();
});

it('lists daily items a store has no price for, and a typed price clears them', function () {
    $variant = dailyRiceOffer($this->lulu);
    signInAdmin($this->operator);

    $page = livewire(MissingShelfPrices::class)
        ->filterTable('store', $this->nesto->id)
        ->assertCanSeeTableRecords([$variant]);

    $page->call('updateTableColumnState', 'shelf_price', (string) $variant, '52.50');

    $price = RegularPrice::where(['product_variant_id' => $variant, 'chain_id' => $this->nesto->id])->firstOrFail();
    expect($price)->price->toBe('52.50')->source->toBe(RegularPrice::SOURCE_STAFF)->created_by->toBe($this->operator->id);

    livewire(MissingShelfPrices::class)->filterTable('store', $this->nesto->id)->assertCanNotSeeTableRecords([$variant]);
});

it('leaves out stores that already have an offer on the item', function () {
    $variant = dailyRiceOffer($this->lulu);
    signInAdmin($this->operator);

    livewire(MissingShelfPrices::class)->filterTable('store', $this->lulu->id)->assertCanNotSeeTableRecords([$variant]);
});

it('keeps a store owner to their own stores on the worklist', function () {
    $variant = dailyRiceOffer($this->lulu);
    $rival = $this->nesto->replicate(['slug'])->fill(['retail_group_id' => null, 'name' => ['en' => 'Carrefour'], 'slug' => 'carrefour']);
    $rival->save();
    signInAdmin($this->owner);

    livewire(MissingShelfPrices::class)
        ->set('tableFilters.store.value', $rival->id)
        ->call('updateTableColumnState', 'shelf_price', (string) $variant, '1.00')
        ->assertForbidden();

    expect(RegularPrice::where('chain_id', $rival->id)->exists())->toBeFalse();
});

it('opens the shelf price screens, and keeps shoppers\' store owners to their permissions', function () {
    signInAdmin($this->operator);
    get('/admin/regular-prices')->assertOk();
    get('/admin/regular-prices/create')->assertOk();
    get(MissingShelfPrices::getUrl())->assertOk();
    get(ManageHomeSettings::getUrl())->assertForbidden();
});

it('corrects a shelf price entered twice on one day', function () {
    signInAdmin($this->operator);
    $variant = Product::where('slug', 'banana')->firstOrFail()->variants()->value('id');
    $form = ['product_variant_id' => $variant, 'chain_id' => $this->lulu->id, 'observed_at' => now('Asia/Dubai')->toDateString()];

    livewire(CreateRegularPrice::class)->fillForm([...$form, 'price' => '5.00'])->call('create')->assertHasNoFormErrors();
    livewire(CreateRegularPrice::class)->fillForm([...$form, 'price' => '6.00'])->call('create')->assertHasNoFormErrors();

    expect(RegularPrice::count())->toBe(1)
        ->and(RegularPrice::first()->price)->toBe('6.00');
});

it('reports how many daily items have enough store prices', function () {
    $this->lulu->update(['grade' => 1]);
    $variant = dailyRiceOffer($this->lulu);
    $third = $this->nesto->replicate(['slug'])->fill(['name' => ['en' => 'Carrefour'], 'slug' => 'carrefour']);
    $third->save();

    expect(app(ShelfPrices::class)->coverage())->toBe(['items' => 1, 'covered' => 0, 'no_photo' => 1]);

    app(ShelfPrices::class)->record($variant, $this->nesto->id, '52.00');
    app(ShelfPrices::class)->record($variant, $third->id, '53.00');

    expect(app(ShelfPrices::class)->coverage())->toBe(['items' => 1, 'covered' => 1, 'no_photo' => 1]);
});
