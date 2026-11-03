<?php

use App\Domain\Access\Access;
use App\Domain\Access\UserStoreAccess;
use App\Domain\Catalog\Product;
use App\Domain\Offers\DirectOffers;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Offers\RegularPrice;
use App\Domain\Retail\Chain;
use App\Filament\Pages\OfferBuilder;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dubai'));
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();

    $this->lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();
    $this->marina = $this->lulu->branches()->create(['name' => ['en' => 'Marina'], 'slug' => 'lulu-marina', 'location' => Point::makeGeodetic(25.08, 55.14)]);
    $this->rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail()->variants()->where('slug', '5-kg')->firstOrFail();
    RegularPrice::create(['product_variant_id' => $this->rice->id, 'chain_id' => $this->lulu->id, 'price' => '50.00', 'observed_at' => '2026-10-20', 'source' => RegularPrice::SOURCE_STAFF]);

    $this->owner = User::factory()->create();
    $this->owner->assignRole('store_owner');
    UserStoreAccess::sync($this->owner, ['chain' => [$this->lulu->id]]);

    // Signs in as a fresh request would, and loads the basmati packs at Lulu.
    $this->builder = function (User $user, array $filters = []) {
        Auth::forgetGuards();
        Access::forget($user->refresh());
        actingAs($user, 'admin');

        return livewire(OfferBuilder::class)
            ->fillForm(['chain_ids' => [$this->lulu->id], 'search' => 'basmati', 'valid_from' => '2026-10-24', 'valid_to' => '2026-10-31', ...$filters])
            ->call('load');
    };
    $this->riceRow = fn ($page) => collect($page->get('targets'))->search(fn ($target) => $target['variant_id'] === $this->rice->id);
});

afterEach(fn () => Offer::removeAllFromSearch());

it('loads a row per store and pack, generates 10% off the shelf price and saves the priced rows', function () {
    $page = ($this->builder)($this->owner, ['limit_text' => 'Max 2 per customer']);
    $i = ($this->riceRow)($page);
    $rows = $page->get('rows');

    expect($i)->not->toBeFalse()
        ->and($page->get('targets')[$i])->toMatchArray(['store_name' => 'Lulu Hypermarket', 'branch_label' => 'All branches', 'usual_price' => '50.00'])
        ->and($rows[$i])->toMatchArray(['selected' => true, 'price' => '', 'was_price' => '50.00', 'limit_text' => 'Max 2 per customer', 'until' => '2026-10-31']);

    $page->callAction(TestAction::make('generate')->schemaComponent('tableActions', schema: 'content'), data: ['percent' => 10, 'base' => 'usual', 'rows' => 'all', 'rounding' => '0.05', 'set_was' => true]);
    expect($page->get("rows.{$i}"))->price->toBe('45.00')->was_price->toBe('50.00');

    // Inline edits: a promotion and a loyalty card price; another row is unticked.
    $page->set("rows.{$i}.promotion", 'was_now')->set("rows.{$i}.loyalty_card", true);
    foreach (array_keys($rows) as $other) {
        if ($other !== $i) {
            $page->set("rows.{$other}.price", '');
        }
    }
    $page->call('save', app(DirectOffers::class));

    $offer = Offer::sole();
    expect($offer)
        ->status->toBe(OfferStatus::Published)
        ->price->toBe('45.00')
        ->regular_price->toBe('50.00')
        ->and($offer->conditions['card_required'])->toBeTrue()
        ->and($offer->getTranslation('condition_label', 'en'))->toBe('Max 2 per customer')
        ->and($page->get("results.{$i}"))->toBe(['ok' => true, 'message' => 'Saved'])
        ->and($page->get("rows.{$i}.selected"))->toBeFalse()
        ->and(Offer::search('basmati')->raw()['found'])->toBe(1);
});

it('shows on the row why it was not saved', function () {
    $page = ($this->builder)($this->owner);
    $i = ($this->riceRow)($page);
    $page->set("rows.{$i}.price", '45.00')->call('save', app(DirectOffers::class));

    // Again within the store users' wait, and a "2 for" without its numbers.
    $page = ($this->builder)($this->owner);
    $page->set("rows.{$i}.price", '44.00')->call('save', app(DirectOffers::class));
    expect($page->get("results.{$i}"))->ok->toBeFalse()->message->toContain('less than 6 hours ago');

    $other = collect($page->get('targets'))->keys()->first(fn ($k) => $k !== $i);
    if ($other !== null) {
        $page->set("rows.{$other}.price", '10.00')->set("rows.{$other}.promotion", 'multibuy')->call('save', app(DirectOffers::class));
        expect($page->get("results.{$other}"))->ok->toBeFalse();
    }
    expect(Offer::count())->toBe(1);
});

it('downloads the table as a CSV, and keeps stores and branches out of the browser’s reach', function () {
    $page = ($this->builder)($this->owner);

    $page->callAction(TestAction::make('download')->schemaComponent('tableActions', schema: 'content'))->assertFileDownloaded('offers-2026-10-24.csv');

    $other = Chain::create(['name' => ['en' => 'Other'], 'slug' => 'other', 'country_id' => $this->lulu->country_id, 'format' => $this->lulu->format]);
    expect(fn () => $page->set('targets.0.chain_id', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('gives branch staff their own branch on every row', function () {
    $employee = User::factory()->create();
    $employee->assignRole('store_staff');
    UserStoreAccess::sync($employee, ['branch' => [$this->marina->id]]);

    $page = ($this->builder)($employee);

    expect(collect($page->get('targets'))->pluck('branch_label')->unique()->all())->toBe(['Marina']);
});
