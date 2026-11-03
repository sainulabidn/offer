<?php

use App\Domain\Access\Access;
use App\Domain\Access\UserStoreAccess;
use App\Domain\Brochures\Brochure;
use App\Domain\Catalog\Product;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Retail\Chain;
use App\Filament\Imports\OfferImporter;
use App\Filament\Resources\Brochures\Pages\EditBrochure;
use App\Filament\Resources\Brochures\Pages\ListBrochures;
use App\Filament\Resources\Brochures\RelationManagers\OffersRelationManager;
use App\Filament\Resources\Offers\Pages\CreateOffer;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dubai'));
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();

    $this->lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();
    $this->barsha = $this->lulu->branches()->firstOrFail();
    $this->marina = $this->lulu->branches()->create(['name' => ['en' => 'Marina'], 'slug' => 'lulu-marina', 'location' => Point::makeGeodetic(25.08, 55.14)]);
    $this->rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail()->variants()->where('slug', '5-kg')->firstOrFail();

    // Lulu's owner manages the whole store; a Lulu employee works at the Marina branch only.
    $this->owner = offerAdmin('store_owner', ['chain' => [$this->lulu->id]]);
    $this->employee = offerAdmin('store_staff', ['branch' => [$this->marina->id]]);
    $this->operator = offerAdmin('data_operator');

    $this->form = fn (array $overrides = []) => [
        'chain_id' => $this->lulu->id,
        'product_variant_id' => $this->rice->id,
        'price' => '45.95',
        'promo_type' => 'price',
        'valid_from' => '2026-10-24',
        'valid_to' => '2026-10-31',
        ...$overrides,
    ];
});

afterEach(fn () => Offer::removeAllFromSearch());

function offerAdmin(string $role, array $stores = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserStoreAccess::sync($user, $stores);

    return $user;
}

function asOfferAdmin(User $user): void
{
    Auth::forgetGuards();
    Access::forget($user->refresh());
    actingAs($user, 'admin');
}

it('puts an offer a store adds live straight away, at every branch', function () {
    asOfferAdmin($this->owner);

    livewire(CreateOffer::class)->fillForm(($this->form)())->call('create')->assertHasNoFormErrors();

    $offer = Offer::sole();
    expect($offer->status)->toBe(OfferStatus::Published)
        ->and($offer->isDirect())->toBeTrue()
        ->and($offer->price_set_at)->not->toBeNull()
        ->and($offer->brochure->branches->modelKeys())->toEqualCanonicalizing([$this->barsha->id, $this->marina->id])
        ->and(Offer::search('basmati')->raw()['found'])->toBe(1);

    // Listed with the offers; its hidden container isn't a brochure to manage.
    livewire(ListOffers::class)->assertCanSeeTableRecords([$offer]);
    livewire(ListBrochures::class)->assertCanNotSeeTableRecords([$offer->brochure]);
});

it('makes store users wait before setting the same pack’s price again', function () {
    config(['offers.price_wait_hours' => 6]);
    asOfferAdmin($this->owner);
    livewire(CreateOffer::class)->fillForm(($this->form)())->call('create')->assertHasNoFormErrors();
    $first = Offer::sole();

    Carbon::setTestNow(now()->addHours(5));
    livewire(CreateOffer::class)->fillForm(($this->form)(['price' => '42.00']))->call('create')->assertHasFormErrors(['price']);
    // Changing the price of the live one is the same.
    livewire(EditOffer::class, ['record' => $first->id])->fillForm(['price' => '42.00'])->call('save')->assertHasFormErrors(['price']);
    // Anything but the price can change.
    livewire(EditOffer::class, ['record' => $first->id])->fillForm(['condition_label' => ['en' => 'Max 2 per customer']])->call('save')->assertHasNoFormErrors();
    // Another pack, or another store, is not affected.
    $oneKg = $this->rice->product->variants()->where('slug', '!=', '5-kg')->firstOrFail();
    livewire(CreateOffer::class)->fillForm(($this->form)(['product_variant_id' => $oneKg->id, 'price' => '9.95']))->call('create')->assertHasNoFormErrors();

    Carbon::setTestNow(now()->addHour());
    livewire(CreateOffer::class)->fillForm(($this->form)(['price' => '42.00']))->call('create')->assertHasNoFormErrors();

    // The new price replaces the earlier one for that pack.
    expect($first->fresh()->status)->toBe(OfferStatus::Withdrawn)
        ->and($first->fresh()->getTranslation('condition_label', 'en'))->toBe('Max 2 per customer')
        ->and(Offer::where('product_variant_id', $this->rice->id)->where('status', OfferStatus::Published)->sole()->price)->toBe('42.00')
        ->and(Offer::search('basmati')->options(['filter_by' => "variant_id:={$this->rice->id}"])->raw()['found'])->toBe(1);
});

it('lets our staff change prices without waiting', function () {
    asOfferAdmin($this->operator);
    livewire(CreateOffer::class)->fillForm(($this->form)())->call('create')->assertHasNoFormErrors();
    livewire(CreateOffer::class)->fillForm(($this->form)(['price' => '42.00']))->call('create')->assertHasNoFormErrors();

    expect(Offer::where('status', OfferStatus::Published)->sole()->price)->toBe('42.00');
});

it('counts brochure offers too', function () {
    asOfferAdmin($this->owner);
    livewire(CreateOffer::class)->fillForm(($this->form)())->call('create')->assertHasNoFormErrors();
    $brochure = Brochure::create(['chain_id' => $this->lulu->id, 'title' => ['en' => 'Weekend'], 'slug' => 'lulu-weekend', 'valid_from' => '2026-10-24', 'valid_to' => '2026-10-26']);

    livewire(OffersRelationManager::class, ['ownerRecord' => $brochure, 'pageClass' => EditBrochure::class])
        ->callAction(TestAction::make(CreateAction::class)->table(), data: ['product_variant_id' => $this->rice->id, 'price' => '41.00', 'promo_type' => 'price', 'status' => 'draft'])
        ->assertHasFormErrors(['price']);

    expect($brochure->offers()->count())->toBe(0);
});

it('keeps an offer for chosen branches apart, and makes branch staff choose theirs', function () {
    asOfferAdmin($this->employee);

    livewire(CreateOffer::class)->fillForm(($this->form)())->call('create')->assertHasFormErrors(['branch_ids' => 'required']);
    livewire(CreateOffer::class)->fillForm(($this->form)(['branch_ids' => [$this->marina->id]]))->call('create')->assertHasNoFormErrors();

    $offer = Offer::sole();
    expect($offer->brochure->branches->modelKeys())->toBe([$this->marina->id])
        ->and($offer->created_by)->toBe($this->employee->id);

    // Their own offer is theirs to change, not the price within the wait.
    livewire(EditOffer::class, ['record' => $offer->id])->fillForm(['valid_to' => '2026-11-02'])->call('save')->assertHasNoFormErrors();
    expect($offer->fresh()->valid_to->toDateString())->toBe('2026-11-02')
        ->and($offer->brochure->fresh()->valid_to->toDateString())->toBe('2026-11-02');
});

it('imports offers from a CSV, one live offer per row', function () {
    $import = Import::create(['user_id' => $this->owner->id, 'file_name' => 'offers.csv', 'file_path' => 'offers.csv', 'importer' => OfferImporter::class, 'total_rows' => 3]);
    $columns = collect(OfferImporter::getColumns())->mapWithKeys(fn ($column) => [$column->getName() => $column->getName()])->all();
    $row = fn (array $values) => (new OfferImporter($import, $columns, []))([
        'store' => 'lulu-hypermarket', 'product' => 'india-gate-classic-basmati-rice', 'pack' => '5-kg', 'price' => '45.95', 'until' => '2026-10-31', ...$values,
    ]);

    $row(['employees_only' => 'yes', 'limit_text' => 'Max 3 kg per customer', 'promotion' => 'multibuy', 'multibuy_qty' => '2', 'multibuy_total' => '85', 'branches' => 'lulu-marina']);

    $offer = Offer::sole();
    expect($offer)
        ->status->toBe(OfferStatus::Published)
        ->created_by->toBe($this->owner->id)
        ->promo_params->toBe(['qty' => 2, 'total' => '85'])
        ->and($offer->isStaffOnly())->toBeTrue()
        ->and($offer->getTranslation('condition_label', 'en'))->toBe('Max 3 kg per customer')
        ->and($offer->brochure->branches->modelKeys())->toBe([$this->marina->id]);

    // The same pack again, within the wait: the row fails and says why.
    expect(fn () => $row(['price' => '40.00']))->toThrow(RowImportFailedException::class, 'less than 6 hours ago');
    expect(fn () => $row(['store' => 'carrefour']))->toThrow(RowImportFailedException::class);
});
