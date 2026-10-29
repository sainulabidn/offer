<?php

use App\Domain\Catalog\Product;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use App\Models\User;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);
    $this->operator = User::factory()->create();
    $this->operator->assignRole('data_operator');
});

it('keeps shoppers out of the admin panel', function () {
    $shopper = User::factory()->create();

    // Signed in on the website: the admin still asks for its own sign-in.
    actingAs($shopper, 'web');
    get('/admin')->assertRedirect('/admin/login');

    actingAs($shopper, 'admin');
    get('/admin')->assertForbidden();
});

it('renders every admin list, create and edit page', function (string $resource, string $model) {
    actingAs($this->operator, 'admin');
    $record = $model::query()->firstOrFail();

    get("/admin/{$resource}")->assertOk();
    get("/admin/{$resource}/create")->assertOk();
    get("/admin/{$resource}/{$record->getKey()}/edit")->assertOk();
})->with([
    'retail groups' => ['retail-groups', App\Domain\Retail\RetailGroup::class],
    'chains' => ['chains', Chain::class],
    'branches' => ['branches', Branch::class],
    'categories' => ['categories', App\Domain\Catalog\Category::class],
    'brands' => ['brands', App\Domain\Catalog\Brand::class],
    'product groups' => ['product-groups', App\Domain\Catalog\ProductGroup::class],
    'products' => ['products', Product::class],
]);

it('renders the brochure list and create pages', function () {
    actingAs($this->operator, 'admin');

    get('/admin/brochures')->assertOk();
    get('/admin/brochures/create')->assertOk();
});

it('adds a variant through the product page', function () {
    actingAs($this->operator, 'admin');
    $rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    $size = $rice->options()->firstOrFail();
    $twoKg = $size->values()->create(['label' => ['en' => '2 kg'], 'code' => '2-kg', 'sort' => 9]);

    livewire(VariantsRelationManager::class, ['ownerRecord' => $rice, 'pageClass' => EditProduct::class])
        ->callAction(TestAction::make(CreateAction::class)->table(), data: [
            'optionValues' => [$twoKg->id],
            'pack_count' => 1,
            'unit_qty' => '2',
            'unit_code' => 'kg',
            'slug' => '2-kg',
        ])
        ->assertHasNoFormErrors();

    $variant = $rice->variants()->where('slug', '2-kg')->firstOrFail();
    expect($variant->net_base_qty)->toBe('2000.000')
        ->and($variant->option_signature)->toBe((string) $twoKg->id);
});

it('rejects a second variant with the same option values', function () {
    actingAs($this->operator, 'admin');
    $rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    $fiveKg = $rice->variants()->where('slug', '5-kg')->firstOrFail();

    livewire(VariantsRelationManager::class, ['ownerRecord' => $rice, 'pageClass' => EditProduct::class])
        ->callAction(TestAction::make(CreateAction::class)->table(), data: [
            'optionValues' => $fiveKg->optionValues->modelKeys(),
            'pack_count' => 1,
            'unit_qty' => '5',
            'unit_code' => 'kg',
            'slug' => '5-kg-again',
        ])
        ->assertHasFormErrors(['optionValues']);
});

it('saves branch coordinates as a PostGIS point', function () {
    actingAs($this->operator, 'admin');
    $chain = Chain::firstOrFail();

    livewire(CreateBranch::class)
        ->fillForm([
            'chain_id' => $chain->id,
            'name' => ['en' => 'Al Nahda'],
            'slug' => 'al-nahda',
            'latitude' => 25.2894,
            'longitude' => 55.3704,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $branch = Branch::where('slug', 'al-nahda')->firstOrFail();
    expect($branch->location->getLatitude())->toEqualWithDelta(25.2894, 0.00001)
        ->and($branch->location->getLongitude())->toEqualWithDelta(55.3704, 0.00001);
});
