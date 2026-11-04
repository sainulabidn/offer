<?php

use App\Domain\Access\Access;
use App\Domain\Access\UserStoreAccess;
use App\Domain\Brochures\BrochureItem;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Brochures\PageStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\Product;
use App\Domain\Flyers\Ai\FakeFlyerReader;
use App\Domain\Flyers\Ai\FlyerReaderManager;
use App\Domain\Flyers\Ai\FlyerReadFailed;
use App\Domain\Flyers\Ai\TokenUsage;
use App\Domain\Flyers\AiBatch;
use App\Domain\Flyers\AiCall;
use App\Domain\Flyers\CreateProductsFromItems;
use App\Domain\Flyers\FlyerAlias;
use App\Domain\Flyers\FlyerBatches;
use App\Domain\Flyers\FlyerSettings;
use App\Domain\Flyers\StartFlyerImport;
use App\Domain\Flyers\SweepFlyers;
use App\Domain\Flyers\VariantMatcher;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Offers\PromoType;
use App\Domain\Retail\Chain;
use App\Domain\Settings\SiteSetting;
use App\Filament\Imports\OfferSheet;
use App\Filament\Pages\ManageFlyerSettings;
use App\Filament\Resources\Brochures\Pages\ReviewFlyer;
use App\Filament\Resources\Offers\Pages\ListOffers;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Support\FlyerPdf;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dubai'));
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();
    Storage::fake('local');

    $this->lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();
    $this->barsha = $this->lulu->branches()->firstOrFail();
    $this->marina = $this->lulu->branches()->create(['name' => ['en' => 'Marina'], 'slug' => 'lulu-marina', 'location' => Point::makeGeodetic(25.08, 55.14)]);
    $rice = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail();
    $this->rice5 = $rice->variants()->where('slug', '5-kg')->firstOrFail();
    $this->milk2 = Product::where('name->en', 'Almarai Full Fat Fresh Milk')->firstOrFail()->variants()->where('slug', '2-l')->firstOrFail();

    $this->operator = flyerStaff('data_operator');
    $this->owner = flyerStaff('store_owner', ['chain' => [$this->lulu->id]]);
    $this->employee = flyerStaff('store_staff', ['branch' => [$this->marina->id]]);

    $this->item = fn (array $overrides = []) => [
        'name' => 'India Gate Classic Basmati Rice', 'ar' => null, 'brand' => 'India Gate', 'size' => '5 kg',
        'price' => 39.95, 'was' => 45.95, 'promo' => 'was_now', 'qty' => null, 'free' => null, 'pct' => null,
        'limit' => null, 'card' => false, 'note' => null, 'barcode' => null, 'box' => [100, 120, 480, 400], 'sure' => 0.95,
        ...$overrides,
    ];
    $this->page = fn (array $items, array $overrides = []) => ['page' => 'offers', 'from' => '2026-10-24', 'until' => '2026-10-30', 'where' => [], 'items' => $items, ...$overrides];
    $this->milk = fn (array $overrides = []) => ($this->item)(['name' => 'Almarai Full Fat Fresh Milk', 'brand' => 'Almarai', 'size' => '2 L', 'price' => 10, 'was' => null, 'promo' => 'multibuy', 'qty' => 2, 'box' => [500, 120, 900, 400], ...$overrides]);
    $this->mystery = fn () => ($this->item)(['name' => 'Crunchy Mystery Chips', 'brand' => null, 'size' => '150 g', 'price' => 3.5, 'was' => null, 'promo' => 'price', 'box' => [100, 500, 400, 800], 'sure' => 0.6]);

    // Flyers in the private disk's upload folder, as the form's file field leaves them.
    $this->upload = function (string $bytes, string $name = 'flyer.pdf'): string {
        $path = 'brochures/incoming/'.uniqid().'-'.$name;
        Storage::disk('local')->put($path, $bytes);

        return $path;
    };
});

afterEach(fn () => Offer::removeAllFromSearch());

function flyerStaff(string $role, array $stores = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserStoreAccess::sync($user, $stores);

    return $user->refresh();
}

function asFlyerStaff(User $user): void
{
    Auth::forgetGuards();
    Access::forget($user->refresh());
    actingAs($user, 'admin');
}

it('reads every page of a picture-only flyer into items, matched and checked', function () {
    $ai = FlyerReaderManager::fake(new FakeFlyerReader([
        ($this->page)([($this->item)(), ($this->milk)()]),
        ($this->page)([($this->mystery)()]),
    ]));

    $brochure = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['Page one', 'Page two']))]);

    $brochure->refresh();
    expect($brochure->status)->toBe(BrochureStatus::Uploaded)
        ->and($brochure->page_count)->toBe(2)
        ->and($brochure->valid_from->toDateString())->toBe('2026-10-24')
        ->and($brochure->valid_to->toDateString())->toBe('2026-10-30')
        ->and($brochure->pages->pluck('ai_status')->all())->toBe([PageStatus::Done, PageStatus::Done])
        ->and($brochure->pages->first()->text())->toBeNull()
        ->and($brochure->pages->first()->width)->toBe(707) // 75 ppi picture → 1000 px tall, never enlarged to 2000
        ->and(count($ai->inputs))->toBe(2)
        ->and(Offer::count())->toBe(0); // nothing becomes an offer before someone accepts it

    $rice = BrochureItem::where('name', 'India Gate Classic Basmati Rice')->sole();
    expect($rice->status)->toBe(ItemStatus::Matched)
        ->and($rice->product_variant_id)->toBe($this->rice5->id)
        ->and($rice->match_method)->toBe('name')
        ->and($rice->bbox)->toEqualCanonicalizing(['x' => 0.1, 'y' => 0.12, 'w' => 0.38, 'h' => 0.28]);

    $milk = BrochureItem::where('name', 'Almarai Full Fat Fresh Milk')->sole();
    expect($milk->product_variant_id)->toBe($this->milk2->id)
        ->and($milk->promo_type)->toBe(PromoType::Multibuy)
        ->and($milk->promo_params)->toBe(['qty' => 2, 'total' => '10.00'])
        ->and((string) $milk->price)->toBe('5.00');

    $mystery = BrochureItem::where('name', 'Crunchy Mystery Chips')->sole();
    expect($mystery->status)->toBe(ItemStatus::New)
        ->and($mystery->product_variant_id)->toBeNull()
        ->and(collect($mystery->issues)->pluck('code')->all())->toContain('product', 'unsure');

    expect(AiCall::count())->toBe(2)
        ->and((float) AiCall::sum('cost_usd'))->toBeGreaterThan(0)
        ->and($this->operator->notifications()->count())->toBe(1);
});

it('reuses the answer for a page it read before, and never re-reads a file it already has', function () {
    $ai = FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([($this->item)()])]));
    $first = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['Weekly deals']))]);

    $again = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['Weekly deals']))]);
    expect($again->id)->toBe($first->id)->and($again->wasRecentlyCreated)->toBeFalse();

    // A regional edition: the same first page, plus one new page.
    $edition = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['Weekly deals', 'Abu Dhabi only']))]);
    $pages = $edition->pages()->get();
    expect($pages[0]->ai_status)->toBe(PageStatus::Reused)
        ->and($pages[0]->items()->count())->toBe(1)
        ->and($pages[1]->ai_status)->toBe(PageStatus::Done)
        ->and(count($ai->inputs))->toBe(2); // first page once, the new page once
});

it('skips text pages without prices and sends the PDF\'s own text with the rest', function () {
    $ai = FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([($this->item)(['price' => 38.5])])]));

    $brochure = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::text([
        ['Terms and conditions apply to all offers', 'while stocks last'],
        ['India Gate Classic Basmati Rice 5 kg', 'Now AED 39.95 was 45.95'],
    ]))]);

    $pages = $brochure->pages()->get();
    expect($pages[0]->ai_status)->toBe(PageStatus::Skipped)
        ->and($pages[1]->ai_status)->toBe(PageStatus::Done)
        ->and(count($ai->inputs))->toBe(1)
        ->and($ai->inputs[0]->text)->toContain('39.95');
    // The AI read 38.50 but the PDF's text says 39.95: flagged for a person.
    expect(collect(BrochureItem::sole()->issues)->pluck('code')->all())->toContain('price_text')
        ->and(BrochureItem::sole()->status)->toBe(ItemStatus::New);
});

it('reads a page in halves when the answer is cut off', function () {
    $ai = FlyerReaderManager::fake(new FakeFlyerReader([
        new FlyerReadFailed('cut off', FlyerReadFailed::TRUNCATED, new TokenUsage(input: 2000, output: 16000), 'claude-haiku-5-5'),
        ($this->page)([($this->item)(['box' => [100, 100, 500, 900]])]), // top half
        ($this->page)([($this->item)(['box' => [100, 0, 500, 100]]), ($this->milk)()]), // bottom half: rice again on the fold
    ]));

    app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['Dense page']))]);

    expect(count($ai->inputs))->toBe(3)
        ->and($ai->inputs[1]->height)->toBeLessThan($ai->inputs[0]->height)
        ->and(BrochureItem::count())->toBe(2) // rice once, milk
        ->and(AiCall::pluck('status')->all())->toBe(['truncated', 'ok', 'ok']);
});

it('holds pages while the monthly budget is spent and reads them once it is raised', function () {
    FlyerSettings::save(['monthly_budget_usd' => 0]);
    $ai = FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([($this->item)()])]));

    $brochure = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['Held page']))]);
    expect($brochure->pages()->sole()->ai_status)->toBe(PageStatus::Held)
        ->and($ai->inputs)->toBe([])
        ->and($brochure->refresh()->status)->toBe(BrochureStatus::Processing);

    FlyerSettings::save(['monthly_budget_usd' => 50]);
    app(SweepFlyers::class)();

    expect($brochure->pages()->sole()->ai_status)->toBe(PageStatus::Done)
        ->and($brochure->refresh()->status)->toBe(BrochureStatus::Uploaded);
});

it('reads economy flyers through a half-price batch, and falls back for pages the batch cuts off', function () {
    $ai = FlyerReaderManager::fake(new FakeFlyerReader([
        ($this->page)([($this->item)()]),
        new FlyerReadFailed('cut off', FlyerReadFailed::TRUNCATED, new TokenUsage(input: 2000, output: 16000), 'claude-haiku-5-5'),
        ($this->page)([($this->milk)()]), // its halves, read the normal way
    ]));
    $ai->batchEnded = false;

    $brochure = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['one', 'two']))], options: ['mode' => 'economy']);
    expect($brochure->pages()->pluck('ai_status')->all())->toBe([PageStatus::Batched, PageStatus::Batched])
        ->and($ai->inputs)->toBe([]);

    $batches = app(FlyerBatches::class);
    expect($batches->submit())->toBe(2)
        ->and($batches->collect())->toBe(0) // still running
        ->and(AiBatch::sole()->status)->toBe('submitted');

    $ai->batchEnded = true;
    expect($batches->collect())->toBe(1);

    $pages = $brochure->pages()->get();
    expect($pages[0]->ai_status)->toBe(PageStatus::Done)
        ->and($pages[1]->ai_status)->toBe(PageStatus::Done)
        ->and(AiBatch::sole()->status)->toBe('ended')
        ->and(AiCall::where('mode', 'batch')->count())->toBe(2)
        ->and((float) AiCall::where('mode', 'batch')->where('status', 'ok')->value('cost_usd'))
        ->toBe(round((3000 * 0.1 + 1500 * 0.01 + 1200 * 0.5) / 1_000_000 * 0.5, 5))
        ->and($brochure->refresh()->status)->toBe(BrochureStatus::Uploaded);
});

it('lets only permitted people import, for the stores and branches they may cover', function () {
    $start = app(StartFlyerImport::class);
    $flyer = fn () => [($this->upload)(FlyerPdf::pictures(['x']))];
    FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([])]));

    // A branch employee names their branch; the whole store is not theirs.
    expect(fn () => $start($this->employee, $this->lulu, $flyer()))->toThrow(ValidationException::class, 'Choose your branches')
        ->and(fn () => $start($this->employee, $this->lulu, $flyer(), [$this->barsha->id]))->toThrow(ValidationException::class, "can't add offers for some");
    expect($start($this->employee, $this->lulu, $flyer(), [$this->marina->id])->scopes->pluck('scope_id')->all())->toBe([$this->marina->id]);

    // A reviewer may publish everywhere, so may import whole-store flyers with only the AI permission.
    expect($start(flyerStaff('reviewer'), $this->lulu, [($this->upload)(FlyerPdf::pictures(['reviewer']))])->exists)->toBeTrue();

    // Without "Import with AI": no button and no import.
    $role = Role::create(['name' => 'brochure_clerk', 'guard_name' => 'web']);
    $role->givePermissionTo(['brochures.view.all', 'brochures.create.all', 'chains.view.all', 'chains.edit.all']);
    $clerk = flyerStaff('brochure_clerk');
    expect(fn () => $start($clerk, $this->lulu, $flyer()))->toThrow(ValidationException::class, "can't import flyers");

    asFlyerStaff($clerk);
    livewire(ListOffers::class)->assertActionHidden('importFlyer');
    asFlyerStaff($this->owner);
    livewire(ListOffers::class)->assertActionVisible('importFlyer');
});

it('offers branches by their real ids, so a picked branch is the one imported for', function () {
    // The picker used to renumber ids 0, 1, 2…: picks failed the import's branch check (and the Offer builder's).
    $options = OfferSheet::branchOptions($this->operator, [$this->lulu->id]);
    expect($options->keys()->all())->toEqualCanonicalizing([$this->barsha->id, $this->marina->id])
        ->and(OfferSheet::branchOptions($this->employee, [$this->lulu->id])->keys()->all())->toBe([$this->marina->id]);

    FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([])]));
    $brochure = app(StartFlyerImport::class)($this->employee, $this->lulu, [($this->upload)(FlyerPdf::pictures(['branch']))], $options->only([$this->marina->id])->keys()->all());
    expect($brochure->scopes->pluck('scope_id')->all())->toBe([$this->marina->id]);
});

it('creates products for unmatched items and accepts their offers for the brochure\'s store', function () {
    FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([
        ($this->mystery)(),
        ($this->item)(['name' => 'Egyptian Istanbul Cheese', 'brand' => null, 'size' => 'per kg', 'price' => 15.9, 'was' => 21.95]),
        ($this->item)(['name' => 'Spanish Green Olives', 'brand' => null, 'size' => '2 L', 'price' => 19.9, 'was' => 24.9]),
        ($this->item)(), // India Gate: already in the catalog
    ], ['until' => '2026-10-20'])])); // a date already past: not stamped on the items
    $brochure = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['new products']))]);
    $items = BrochureItem::pluck('id', 'name');
    expect(BrochureItem::whereNotNull('valid_to')->count())->toBe(0);

    asFlyerStaff($this->operator);
    $page = livewire(ReviewFlyer::class, ['record' => $brochure->id])
        ->callAction(TestAction::make('selectUnmatched')->schemaComponent('pageActions', schema: 'content'));
    expect($page->get('selected'))->toEqualCanonicalizing([$items['Crunchy Mystery Chips'], $items['Egyptian Istanbul Cheese'], $items['Spanish Green Olives']]);
    $page->mountAction(TestAction::make('createProducts')->schemaComponent('pageActions', schema: 'content'))
        ->assertMountedActionModalSee('Product group for all');
    expect(collect($page->get('mountedActions.0.data.rows'))->pluck('size', 'name')->all())
        ->toBe(['Crunchy Mystery Chips' => '150 g', 'Egyptian Istanbul Cheese' => 'per kg', 'Spanish Green Olives' => '2 L']);

    $group = Product::where('slug', 'india-gate-classic-basmati-rice')->sole()->product_group_id; // compares per kg
    $result = app(CreateProductsFromItems::class)($brochure, [
        $items['Crunchy Mystery Chips'] => ['name' => 'Crunchy Mystery Chips', 'brand' => 'Crunchy', 'size' => '150 g', 'product_group_id' => $group],
        $items['Egyptian Istanbul Cheese'] => ['name' => 'Egyptian Istanbul Cheese', 'brand' => null, 'size' => 'per kg', 'product_group_id' => $group],
        $items['Spanish Green Olives'] => ['name' => 'Spanish Green Olives', 'brand' => null, 'size' => '2 L', 'product_group_id' => $group], // litres in a per-kg group
        $items['India Gate Classic Basmati Rice'] => ['name' => 'India Gate Classic Basmati Rice', 'size' => '5 kg', 'product_group_id' => $group],
    ], $this->operator);

    expect($result['created'])->toBe(2)
        ->and($result['failed'])->toHaveKey($items['Spanish Green Olives'])
        ->and($result['failed'])->not->toHaveKey($items['India Gate Classic Basmati Rice']) // matched already: left alone
        ->and(Product::where('slug', 'egyptian-istanbul-cheese')->sole()->is_variable_weight)->toBeTrue();

    $chips = BrochureItem::find($items['Crunchy Mystery Chips']);
    expect($chips->status)->toBe(ItemStatus::Accepted)
        ->and($chips->match_method)->toBe('created')
        ->and($chips->offer->brochure_id)->toBe($brochure->id)
        ->and($chips->offer->chain_id)->toBe($this->lulu->id)
        ->and($chips->offer->status)->toBe(OfferStatus::Draft)
        ->and(app(VariantMatcher::class)->match(['name' => 'Crunchy Mystery Chips', 'brand_text' => null, 'size_text' => '150 g'], $this->lulu->id)['match_method'])->toBe('alias');
});

it('stops at the daily page quota', function () {
    FlyerSettings::save(['user_daily_pages' => 2]);
    FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([])]));

    app(StartFlyerImport::class)($this->owner, $this->lulu, [($this->upload)(FlyerPdf::pictures(['a', 'b']))]);

    expect(fn () => app(StartFlyerImport::class)($this->owner, $this->lulu, [($this->upload)(FlyerPdf::pictures(['c']))]))
        ->toThrow(ValidationException::class, 'flyer pages a day');
});

it('turns accepted items into draft offers, learns the match and publishes only those', function () {
    FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([($this->item)(), ($this->milk)(), ($this->mystery)()])]));
    $brochure = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['p']))]);
    $rice = BrochureItem::where('product_variant_id', $this->rice5->id)->sole();

    asFlyerStaff($this->operator);
    livewire(ReviewFlyer::class, ['record' => $brochure->id])
        ->assertOk()
        ->assertSee('Crunchy Mystery Chips')
        ->call('acceptItem', $rice->id);

    $offer = Offer::sole();
    expect($offer->status)->toBe(OfferStatus::Draft)
        ->and($offer->brochure_page_id)->toBe($rice->brochure_page_id)
        ->and($offer->bbox)->toEqualCanonicalizing($rice->bbox)
        ->and((string) $offer->regular_price)->toBe('45.95')
        ->and($rice->refresh()->status)->toBe(ItemStatus::Accepted)
        ->and(FlyerAlias::where('product_variant_id', $this->rice5->id)->exists())->toBeTrue();

    // Next week the same printed name matches straight from what was learned.
    expect(app(VariantMatcher::class)->match(['name' => 'India Gate Classic Basmati Rice', 'brand_text' => null, 'size_text' => '5 kg'], $this->lulu->id)['match_method'])->toBe('alias');

    app(PublishBrochure::class)($brochure->refresh());
    expect(Offer::where('status', OfferStatus::Published)->count())->toBe(1);
});

it('publishes clean flyers by itself in auto mode, but only for people who may publish', function () {
    FlyerSettings::save(['publish_mode' => 'auto']);
    FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([($this->item)(), ($this->milk)()])]));

    $mine = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['operator']))]);
    expect($mine->refresh()->status)->toBe(BrochureStatus::Published)
        ->and($mine->offers()->where('status', OfferStatus::Published)->count())->toBe(2);

    // A store owner without the publish right reviews and submits as usual.
    $theirs = app(StartFlyerImport::class)($this->owner, $this->lulu, [($this->upload)(FlyerPdf::pictures(['owner']))]);
    expect($theirs->refresh()->status)->toBe(BrochureStatus::Uploaded)
        ->and($theirs->offers()->count())->toBe(0);
});

it('keeps other stores\' flyers out of reach on the review screen', function () {
    FlyerReaderManager::fake(new FakeFlyerReader([($this->page)([($this->item)()])]));
    $brochure = app(StartFlyerImport::class)($this->operator, $this->lulu, [($this->upload)(FlyerPdf::pictures(['p']))]);
    $other = Chain::create(['name' => ['en' => 'Other Mart'], 'slug' => 'other-mart', 'format' => 'supermarket', 'country_id' => $this->lulu->country_id]);
    $outsider = flyerStaff('store_owner', ['chain' => [$other->id]]);

    asFlyerStaff($outsider);
    expect($this->get(ReviewFlyer::getUrl(['record' => $brochure]))->status())->toBeIn([403, 404]);

    asFlyerStaff($this->owner);
    $this->get(ReviewFlyer::getUrl(['record' => $brochure]))->assertOk();
});

it('keeps flyer AI settings to settings editors and never stores API keys', function () {
    asFlyerStaff($this->owner);
    $this->get(ManageFlyerSettings::getUrl())->assertForbidden();

    $admin = flyerStaff('super_admin');
    asFlyerStaff($admin);
    livewire(ManageFlyerSettings::class)
        ->assertOk()
        ->set('data.model', 'claude-haiku-5-5')
        ->set('data.publish_mode', 'auto')
        ->call('save');

    expect(FlyerSettings::get('model'))->toBe('claude-haiku-5-5')
        ->and(FlyerSettings::get('publish_mode'))->toBe('auto')
        // A saved price table still gets models added to the defaults later.
        ->and(FlyerSettings::get('prices'))->toHaveKeys(['claude-haiku-5-5', 'gpt-6.1-sol', 'gpt-6-luna']);

    // OpenAI models are offered with their cost; a model of the other provider is refused.
    livewire(ManageFlyerSettings::class)
        ->assertSee('gpt-6.1-sol')
        ->set('data.provider', 'openai')
        ->set('data.model', 'claude-opus-5-5')
        ->call('save');
    expect(FlyerSettings::get('provider'))->toBe('claude');

    livewire(ManageFlyerSettings::class)->set('data.provider', 'openai')->set('data.model', 'gpt-6.1-terra')->call('save');
    expect(FlyerSettings::get('provider'))->toBe('openai')
        ->and(FlyerSettings::get('model'))->toBe('gpt-6.1-terra')
        ->and(SiteSetting::pluck('key')->filter(fn ($key) => str_contains($key, 'key'))->all())->toBe([]);
});
