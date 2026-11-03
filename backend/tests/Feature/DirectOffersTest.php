<?php

use App\Domain\Access\Access;
use App\Domain\Access\UserStoreAccess;
use App\Domain\Brochures\Brochure;
use App\Domain\Catalog\Product;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Retail\Chain;
use App\Filament\Imports\OfferImporter;
use App\Filament\Imports\OfferImportGuide;
use App\Filament\Imports\OfferSheet;
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

it('guides the CSV import, with a sample from the person’s own store that imports as it is', function () {
    asOfferAdmin($this->owner);

    livewire(ListOffers::class)->mountAction('importHelp')
        ->assertMountedActionModalSee(['lulu-hypermarket', 'once every 6 hours', 'Pack not found']);
    foreach (['sample' => 'offers-sample.csv', 'stores' => 'stores-and-branches.csv', 'packs' => 'pack-list.csv'] as $action => $file) {
        livewire(ListOffers::class)->callAction([TestAction::make('importHelp'), TestAction::make($action)])->assertFileDownloaded($file);
    }
    // The import window still offers Filament's example file.
    livewire(ListOffers::class)->mountAction('import')->assertMountedActionModalSee('Download example');

    $sample = OfferImportGuide::sample($this->owner);
    expect($sample[0]['store'])->toBe('lulu-hypermarket')
        ->and($sample[2]['branches'])->toBe("{$this->barsha->slug}|lulu-marina");

    $import = Import::create(['user_id' => $this->owner->id, 'file_name' => 'offers.csv', 'file_path' => 'offers.csv', 'importer' => OfferImporter::class, 'total_rows' => 3]);
    $columns = array_combine(array_keys(OfferImportGuide::COLUMNS), array_keys(OfferImportGuide::COLUMNS));
    foreach ($sample as $row) {
        (new OfferImporter($import, $columns, []))($row);
    }

    expect(Offer::where('status', OfferStatus::Published)->count())->toBe(3)
        ->and(Offer::get()->filter->isStaffOnly())->toHaveCount(1);
});

it('makes offer sheet rows for the person’s own stores and chosen products, and imports only the priced rows', function () {
    $nesto = Chain::create(['name' => ['en' => 'Nesto'], 'slug' => 'nesto', 'country_id' => $this->lulu->country_id, 'format' => $this->lulu->format, 'retail_group_id' => $this->lulu->retail_group_id]);
    $nesto->branches()->create(['name' => ['en' => 'Al Nahda'], 'slug' => 'nesto-al-nahda', 'location' => Point::makeGeodetic(25.29, 55.37)]);
    $riceCategory = $this->rice->product->category;
    $options = [
        // Nesto isn't theirs: left out even when asked for.
        'chain_ids' => [$this->lulu->id, $nesto->id],
        'category_ids' => [$riceCategory->parent_id ?? $riceCategory->id],
        'valid_from' => '2026-10-24', 'valid_to' => '2026-10-26', 'start_time' => '08:00:00', 'end_time' => '11:00:00',
        'limit_text' => 'Max 2 per customer', 'employees_only' => false,
    ];

    $rows = OfferSheet::rows($this->owner, $options);
    $packs = OfferSheet::packs($options)->count();
    expect($rows)->toHaveCount($packs)
        ->and(collect($rows)->pluck('store')->unique()->all())->toBe(['lulu-hypermarket'])
        ->and($rows[0])->toMatchArray(['branches' => '', 'from' => '2026-10-24', 'until' => '2026-10-26', 'from_time' => '08:00', 'until_time' => '11:00', 'limit_text' => 'Max 2 per customer'])
        ->and(collect($rows)->pluck('name')->filter())->toHaveCount($packs)
        ->and(collect($rows)->contains(fn ($row) => ($row['product'] ?? null) === 'banana'))->toBeFalse();
    // Someone at one branch gets that branch on every row.
    expect(OfferSheet::rows($this->employee, $options)[0]['branches'])->toBe('lulu-marina');

    // The person prices one row and imports the whole sheet: the rest are skipped, not failed.
    $rows[0]['price'] = '39.95';
    $import = Import::create(['user_id' => $this->owner->id, 'file_name' => 'sheet.csv', 'file_path' => 'sheet.csv', 'importer' => OfferImporter::class, 'total_rows' => count($rows)]);
    $columns = array_combine(array_keys(OfferImportGuide::COLUMNS), array_keys(OfferImportGuide::COLUMNS));
    foreach ($rows as $row) {
        (new OfferImporter($import, $columns, []))(OfferSheet::csvRow($row));
    }
    $import->forceFill(['successful_rows' => count($rows), 'processed_rows' => count($rows), 'completed_at' => now()->addSecond()])->save();

    expect(Offer::sole())->price->toBe('39.95')->start_time->toBe('08:00:00')
        ->and(OfferImporter::getCompletedNotificationBody($import))->toContain('1 offer added')
        ->when(count($rows) > 1, fn ($e) => $e->toContain((count($rows) - 1).' '.str('row')->plural(count($rows) - 1).' without a price skipped'));
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
