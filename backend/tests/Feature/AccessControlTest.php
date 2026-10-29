<?php

use App\Domain\Access\Access;
use App\Domain\Access\ReviewStatus;
use App\Domain\Access\UserStoreAccess;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Catalog\Product;
use App\Domain\Geo\Country;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ChainFormat;
use App\Domain\Retail\RetailGroup;
use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Filament\Resources\Brochures\Pages\EditBrochure;
use App\Filament\Resources\Brochures\RelationManagers\OffersRelationManager;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

// Lulu (demo data) and Nesto, each a retail group with one chain and one branch.
beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);

    $this->lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();
    $this->luluBranch = $this->lulu->branches()->firstOrFail();
    $this->nestoGroup = RetailGroup::create(['name' => ['en' => 'Nesto Group'], 'slug' => 'nesto-group']);
    $this->nesto = Chain::create([
        'retail_group_id' => $this->nestoGroup->id,
        'country_id' => Country::where('code', 'AE')->value('id'),
        'name' => ['en' => 'Nesto Hypermarket'],
        'slug' => 'nesto-hypermarket',
        'format' => ChainFormat::Hypermarket,
    ]);
    $this->nestoBranch = $this->nesto->branches()->create([
        'name' => ['en' => 'Al Nahda'], 'slug' => 'nesto-al-nahda', 'location' => Point::makeGeodetic(25.29, 55.37),
    ]);

    // Nesto's owner works on the whole Nesto group.
    $this->owner = staff('store_owner', ['retail_group' => [$this->nestoGroup->id]]);
});

function staff(string $role, array $stores = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserStoreAccess::sync($user, $stores);

    return $user;
}

/** Sign in to the admin as a fresh request would: no permission or guard state left over. */
function asAdmin(User $user): void
{
    Auth::forgetGuards();
    Access::forget($user->refresh());
    actingAs($user, 'admin');
}

it('shows a store owner only their own stores', function () {
    asAdmin($this->owner);

    get('/admin/chains')->assertOk()->assertSee('Nesto Hypermarket')->assertDontSee('Lulu Hypermarket');
    livewire(ListBranches::class)->assertCanSeeTableRecords([$this->nestoBranch])->assertCanNotSeeTableRecords([$this->luluBranch]);
    get("/admin/branches/{$this->nestoBranch->id}/edit")->assertOk();
    // Other retailers' records don't exist for them (not even a "forbidden").
    get("/admin/branches/{$this->luluBranch->id}/edit")->assertNotFound();
    get("/admin/chains/{$this->lulu->id}/edit")->assertNotFound();
});

it('does not let a store owner put a branch into another retailer’s chain', function () {
    asAdmin($this->owner);
    $branch = ['name' => ['en' => 'Karama'], 'slug' => 'karama', 'latitude' => 25.24, 'longitude' => 55.30];

    livewire(CreateBranch::class)->fillForm([...$branch, 'chain_id' => $this->lulu->id])->call('create')->assertHasFormErrors(['chain_id']);
    livewire(CreateBranch::class)->fillForm([...$branch, 'chain_id' => $this->nesto->id])->call('create')->assertHasNoFormErrors();

    expect(Branch::where('slug', 'karama')->value('created_by'))->toBe($this->owner->id);
});

it('gives a branch-only user just that branch', function () {
    $manager = staff('store_staff', ['branch' => [$this->nestoBranch->id]]);
    $otherNestoBranch = $this->nesto->branches()->create(['name' => ['en' => 'Karama'], 'slug' => 'nesto-karama', 'location' => Point::makeGeodetic(25.24, 55.30)]);
    asAdmin($manager);

    get("/admin/branches/{$this->nestoBranch->id}/edit")->assertOk();
    get("/admin/branches/{$otherNestoBranch->id}/edit")->assertNotFound();
    get('/admin/chains')->assertOk()->assertSee('Nesto Hypermarket'); // to pick it for a brochure
    get("/admin/chains/{$this->nesto->id}/edit")->assertForbidden(); // visible, not editable
});

it('lets someone with "all stores" reach every store', function () {
    $owner = staff('store_owner');
    $owner->update(['all_stores' => true]);
    asAdmin($owner);

    get("/admin/branches/{$this->luluBranch->id}/edit")->assertOk();
    get("/admin/branches/{$this->nestoBranch->id}/edit")->assertOk();
});

it('gives a store owner the retail group they create', function () {
    $owner = staff('store_owner');
    asAdmin($owner);

    $group = RetailGroup::create(['name' => ['en' => 'Grandiose'], 'slug' => 'grandiose']);

    expect(UserStoreAccess::idsFor($owner)['retail_group'])->toBe([$group->id]);
    get("/admin/retail-groups/{$group->id}/edit")->assertOk();
});

it('keeps products added by a store owner off the website until staff approve them', function () {
    asAdmin($this->owner);
    $template = Product::firstOrFail();
    $atta = Product::create([
        'name' => ['en' => 'Nesto Chakki Atta'], 'slug' => 'nesto-chakki-atta', 'status' => 'active',
        'product_group_id' => $template->product_group_id, 'category_id' => $template->category_id,
    ]);

    expect($atta->review_status)->toBe(ReviewStatus::Pending)->and($atta->created_by)->toBe($this->owner->id);
    getJson('/api/v1/products/nesto-chakki-atta')->assertNotFound();
    get('/admin/products')->assertSee('Nesto Chakki Atta');

    // Another retailer doesn't even know it exists while it waits.
    asAdmin(staff('store_owner', ['chain' => [$this->lulu->id]]));
    get('/admin/products')->assertOk()->assertDontSee('Nesto Chakki Atta');
    get("/admin/products/{$atta->id}/edit")->assertNotFound();

    asAdmin(staff('reviewer'));
    livewire(ListProducts::class)->callAction(TestAction::make('approve')->table($atta))->assertHasNoErrors();
    expect($atta->refresh()->review_status)->toBe(ReviewStatus::Approved);
    getJson('/api/v1/products/nesto-chakki-atta')->assertOk();
});

it('lets store owners edit only the products they added', function () {
    asAdmin($this->owner);
    $template = Product::firstOrFail();
    $own = Product::create([
        'name' => ['en' => 'Nesto Basmati'], 'slug' => 'nesto-basmati', 'status' => 'active',
        'product_group_id' => $template->product_group_id, 'category_id' => $template->category_id,
    ]);

    get("/admin/products/{$own->id}/edit")->assertOk();
    get("/admin/products/{$template->id}/edit")->assertForbidden();
    get('/admin/products')->assertOk()->assertSee($template->name); // but sees the whole approved catalog
});

it('sends a brochure for review when the owner may not publish, and lets them publish once allowed', function () {
    $operator = staff('data_operator');
    asAdmin($this->owner);
    $brochure = Brochure::create([
        'chain_id' => $this->nesto->id, 'title' => ['en' => 'Nesto weekend'], 'slug' => 'nesto-weekend',
        'valid_from' => now()->toDateString(), 'valid_to' => now()->addWeek()->toDateString(),
    ]);

    livewire(EditBrochure::class, ['record' => $brochure->getRouteKey()])
        ->assertActionHidden('publish')
        ->assertActionVisible('submit')
        ->callAction('submit');

    expect($brochure->refresh()->status)->toBe(BrochureStatus::InReview)
        ->and($operator->notifications()->count())->toBe(1);

    Role::findByName('store_owner', 'web')->givePermissionTo('brochures.publish.assigned');
    asAdmin($this->owner);
    livewire(EditBrochure::class, ['record' => $brochure->getRouteKey()])->assertActionVisible('publish');
});

it('freezes a live brochure’s offers for people who may not publish', function () {
    $brochure = Brochure::create([
        'chain_id' => $this->nesto->id, 'title' => ['en' => 'Nesto weekly'], 'slug' => 'nesto-weekly', 'status' => BrochureStatus::Published,
        'valid_from' => now()->toDateString(), 'valid_to' => now()->addWeek()->toDateString(),
    ]);
    asAdmin($this->owner);

    livewire(OffersRelationManager::class, ['ownerRecord' => $brochure, 'pageClass' => EditBrochure::class])
        ->assertActionHidden(TestAction::make(CreateAction::class)->table());

    asAdmin(staff('data_operator'));
    livewire(OffersRelationManager::class, ['ownerRecord' => $brochure, 'pageClass' => EditBrochure::class])
        ->assertActionVisible(TestAction::make(CreateAction::class)->table());
});

it('builds a role from the permission grid', function () {
    asAdmin(User::where('email', 'admin@offer.test')->firstOrFail());

    livewire(CreateRole::class)
        ->fillForm(['name' => 'lulu_buyer', 'grid' => [
            'branches' => ['view' => 'assigned', 'edit' => 'own'],
            'products' => ['view' => 'all', 'approve' => 'all'],
        ]])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::findByName('lulu_buyer', 'web');
    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['branches.edit.own', 'branches.view.assigned', 'products.approve.all', 'products.view.all']);

    livewire(EditRole::class, ['record' => $role->getRouteKey()])
        ->assertSchemaStateSet(['grid.branches.view' => 'assigned', 'grid.branches.edit' => 'own', 'grid.products.approve' => 'all', 'grid.chains.view' => null]);
});

it('creates an admin user with roles and stores', function () {
    asAdmin(User::where('email', 'admin@offer.test')->firstOrFail());

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Nesto Manager', 'email' => 'manager@nesto.example', 'password' => 'a-long-password',
            'roles' => [Role::findByName('store_owner', 'web')->id],
            'store_chains' => [$this->nesto->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'manager@nesto.example')->firstOrFail();
    expect($user->hasRole('store_owner'))->toBeTrue()
        ->and(UserStoreAccess::idsFor($user)['chain'])->toBe([$this->nesto->id]);

    asAdmin($user);
    get("/admin/branches/{$this->nestoBranch->id}/edit")->assertOk();
    get("/admin/branches/{$this->luluBranch->id}/edit")->assertNotFound();
});

it('keeps super admin out of reach of other user managers', function () {
    $manager = staff('data_operator');
    Role::findByName('data_operator', 'web')->givePermissionTo(['users.view.all', 'users.create.all', 'users.edit.all']);
    asAdmin($manager);

    livewire(CreateUser::class)
        ->fillForm(['name' => 'Sneaky', 'email' => 'sneaky@example.com', 'password' => 'a-long-password', 'roles' => [Role::findByName('super_admin', 'web')->id]])
        ->call('create')
        ->assertHasFormErrors(['roles']);

    $admin = User::where('email', 'admin@offer.test')->firstOrFail();
    get("/admin/users/{$admin->id}/edit")->assertForbidden();
});

it('locks out disabled users and shoppers', function () {
    $this->owner->update(['disabled_at' => now()]);
    asAdmin($this->owner);
    get('/admin')->assertForbidden();

    asAdmin(User::factory()->create());
    get('/admin')->assertForbidden();
});

it('records who changed what in the activity log', function () {
    asAdmin($this->owner);
    $this->nestoBranch->update(['phone' => '+971 4 000 0000']);

    $entry = Activity::query()->where('subject_type', 'branch')->where('event', 'updated')->latest('id')->firstOrFail();
    expect($entry->causer_id)->toBe($this->owner->id)
        ->and($entry->attribute_changes['attributes']['phone'])->toBe('+971 4 000 0000');
});

it('renders the users, roles and activity screens', function () {
    asAdmin(User::where('email', 'admin@offer.test')->firstOrFail());
    $this->nestoBranch->update(['phone' => '+971 4 111 1111']); // something in the activity log

    get('/admin/users')->assertOk()->assertSee('admin@offer.test');
    get("/admin/users/{$this->owner->id}/edit")->assertOk()->assertSee('Nesto Group');
    get('/admin/roles')->assertOk()->assertSee('Store owner');
    get('/admin/roles/'.Role::findByName('store_owner', 'web')->id.'/edit')->assertOk()->assertSee('Their stores');
    get('/admin/activities')->assertOk()->assertSee('4 111 1111'); // the HTML sanitizer writes "+" as &#43;
});
