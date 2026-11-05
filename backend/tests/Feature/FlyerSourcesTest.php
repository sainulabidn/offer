<?php

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Flyers\Ai\FakeFlyerReader;
use App\Domain\Flyers\Ai\FlyerReaderManager;
use App\Domain\Flyers\FlyerBatches;
use App\Domain\Flyers\Sources\BrochureSource;
use App\Domain\Flyers\Sources\CheckSources;
use App\Domain\Flyers\Sources\FetchBrochureSource;
use App\Domain\Flyers\Sources\SourceFetcher;
use App\Domain\Offers\Offer;
use App\Domain\Retail\BrochureFrequency;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ContentPermission;
use App\Filament\Pages\FlyerCollection;
use App\Filament\Resources\Chains\Pages\EditChain;
use App\Filament\Resources\Chains\RelationManagers\BrochureSourcesRelationManager;
use App\Models\User;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FlyerPdf;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dubai'));
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);
    Offer::removeAllFromSearch();
    Storage::fake('local');
    config(['flyers.resolve_hosts' => false]); // test hosts don't resolve; IP addresses are still checked

    $this->lulu = Chain::where('slug', 'lulu-hypermarket')->firstOrFail();
    $this->lulu->update(['content_permission' => ContentPermission::Granted, 'brochure_frequency' => BrochureFrequency::Weekly]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    FlyerReaderManager::fake(new FakeFlyerReader([['page' => 'offers', 'from' => null, 'until' => null, 'where' => [], 'items' => []]]));
    $this->pdf = FlyerPdf::pictures(['Lulu weekly']);
    $this->source = fn (array $attributes = []) => $this->lulu->brochureSources()->create([
        'name' => 'Weekly flyer', 'kind' => BrochureSource::KIND_PDF, 'url' => 'https://flyers.lulu.test/weekly.pdf', ...$attributes,
    ]);
});

afterEach(fn () => Offer::removeAllFromSearch());

it('imports a new flyer, then costs nothing while it stays the same', function () {
    Http::fake(function (Request $request) {
        return match (true) {
            str_ends_with($request->url(), '/robots.txt') => Http::response('', 404),
            $request->hasHeader('If-None-Match', '"v1"') => Http::response('', 304),
            default => Http::response($this->pdf, 200, ['ETag' => '"v1"', 'Content-Type' => 'application/pdf']),
        };
    });
    $source = ($this->source)();

    FetchBrochureSource::dispatchSync($source->id);

    // Nobody waits for a fetched flyer: its pages go through the half-price batch.
    $brochure = Brochure::sole();
    expect($brochure->ai_mode)->toBe('economy')
        ->and($brochure->status)->toBe(BrochureStatus::Processing);
    $batches = app(FlyerBatches::class);
    $batches->submit();
    $batches->collect();

    $brochure->refresh();
    expect($brochure->brochure_source_id)->toBe($source->id)
        ->and($brochure->source)->toBe('auto_fetch')
        ->and($brochure->status)->toBe(BrochureStatus::InReview) // no uploader: publishers check it
        ->and($source->refresh()->etag)->toBe('"v1"')
        ->and($source->fetches()->sole()->outcome)->toBe('new')
        ->and($this->admin->notifications()->count())->toBe(1);
    Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', SourceFetcher::USER_AGENT));

    FetchBrochureSource::dispatchSync($source->id);

    expect(Brochure::count())->toBe(1)
        ->and($source->fetches()->first()->outcome)->toBe('unchanged')
        ->and($source->fetches()->first()->http_status)->toBe(304);
});

it('finds new flyer links on a store page and skips the ones it has', function () {
    Http::fake([
        'shop.test/robots.txt' => Http::response("User-agent: *\nDisallow: /private/", 200),
        'shop.test/offers' => Http::response('<a href="/files/week-45.pdf">This week</a> <a href="/about">About</a> <a href="https://shop.test/private/secret.pdf">x</a>'),
        'shop.test/files/week-45.pdf' => Http::response($this->pdf, 200),
    ]);
    $source = ($this->source)(['kind' => BrochureSource::KIND_PAGE, 'url' => 'https://shop.test/offers']);

    FetchBrochureSource::dispatchSync($source->id);
    FetchBrochureSource::dispatchSync($source->id);

    expect(Brochure::sole()->source_url)->toBe('https://shop.test/files/week-45.pdf')
        ->and($source->fetches()->pluck('outcome')->all())->toBe(['unchanged', 'new']);
    Http::assertSentCount(4); // robots.txt once (cached), the page twice, the PDF once
    expect(SourceFetcher::links('<a href="https://shop.test/private/secret.pdf">x</a>', 'https://shop.test/offers', BrochureSource::DEFAULT_PATTERN))->toHaveCount(1);
    expect(app(SourceFetcher::class)->allowedByRobots('https://shop.test/private/secret.pdf'))->toBeFalse();
});

it('reads flyers that flipbooks and app pages name in scripts, and skips links the store has deleted', function () {
    $page = <<<'HTML'
        <a href="/files/2019-old.pdf">Old</a>
        <script>window.option_df_1 = {"source":"https:\/\/shop.test\/uploads\/week-45.pdf","wpOptions":"true"};</script>
        <script>var real3dflipbook_6 = "{\"pdfUrl\":\"https:\\/\\/shop.test\\/uploads\\/weekend.pdf\"}";</script>
        <iframe src="https://shop.test/pdfjs/viewer.html?file=https://shop.test/uploads/home.pdf"></iframe>
        <script>self.__next_f.push([1,"{\"path\":\"https://cdn.test/flyers/1793-GMJ Smart Deals 2 to 5 Nov.pdf\",\"outlets\":[]}"])</script>
        HTML;

    expect(SourceFetcher::links($page, 'https://shop.test/offers', BrochureSource::DEFAULT_PATTERN))->toBe([
        'https://shop.test/files/2019-old.pdf',
        'https://shop.test/uploads/week-45.pdf',
        'https://shop.test/uploads/weekend.pdf',
        'https://shop.test/uploads/home.pdf',
        'https://cdn.test/flyers/1793-GMJ%20Smart%20Deals%202%20to%205%20Nov.pdf',
    ])->and(SourceFetcher::links($page, 'https://shop.test/offers', '/flyers/\d+-GMJ'))->toHaveCount(1);

    Http::fake([
        'shop.test/robots.txt' => Http::response('', 404),
        'shop.test/offers' => Http::response('<a href="/files/2019-old.pdf">Old</a> <a href="/files/week-45.pdf">This week</a>'),
        'shop.test/files/2019-old.pdf' => Http::response('Not found', 404),
        'shop.test/files/week-45.pdf' => Http::response($this->pdf, 200),
    ]);
    $source = ($this->source)(['kind' => BrochureSource::KIND_PAGE, 'url' => 'https://shop.test/offers']);

    FetchBrochureSource::dispatchSync($source->id);
    FetchBrochureSource::dispatchSync($source->id);

    expect(Brochure::sole()->source_url)->toBe('https://shop.test/files/week-45.pdf')
        ->and($source->refresh()->consecutive_failures)->toBe(0)
        ->and($source->fetches()->pluck('outcome')->all())->toBe(['unchanged', 'new', 'gone']);
});

it('reads a Nesto store\'s offers with Nesto\'s own dates, and downloads each flyer once', function () {
    Http::fake([
        '*/robots.txt' => Http::response('', 404),
        'admin.nesto.test/api/deal-of-the-week' => Http::response(['data' => [
            ['id' => 1, 'name' => 'BIG SALE', 'start_date' => '2026-10-24 00:00:00', 'end_date' => '2026-10-27 00:00:00', 'pdfs' => [
                ['id' => 11, 'title' => 'BIG SALE 25% TO 70% OFF', 'pdf_url' => 'https://cdn.nesto.test/offers-pdf/1.pdf'],
            ]],
            // Published ahead: it starts next week.
            ['id' => 2, 'name' => 'Home Fiesta', 'start_date' => '2026-10-31 00:00:00', 'end_date' => '2026-11-09 00:00:00', 'pdfs' => [
                ['id' => 12, 'title' => 'Home Fiesta', 'pdf_url' => 'https://cdn.nesto.test/offers-pdf/2.pdf'],
            ]],
        ]]),
        'cdn.nesto.test/offers-pdf/1.pdf' => Http::response($this->pdf),
        'cdn.nesto.test/offers-pdf/2.pdf' => Http::response(FlyerPdf::pictures(['Nesto next week'])),
    ]);
    $source = ($this->source)(['kind' => BrochureSource::KIND_NESTO, 'url' => 'https://admin.nesto.test/api/deal-of-the-week?store_id=125&location_id=5']);

    FetchBrochureSource::dispatchSync($source->id);
    FetchBrochureSource::dispatchSync($source->id);

    expect(Brochure::orderBy('valid_from')->get()->map(fn (Brochure $b) => [$b->getTranslation('title', 'en'), $b->valid_from->toDateString(), $b->valid_to->toDateString()])->all())
        ->toBe([['BIG SALE 25% TO 70% OFF', '2026-10-24', '2026-10-27'], ['Home Fiesta', '2026-10-31', '2026-11-09']])
        ->and($source->fetches()->pluck('outcome')->all())->toBe(['unchanged', 'new', 'new']);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['store_id'] === 125 && $request['location_id'] === 5);
    // The second check lists the offers again but downloads nothing it has.
    expect(collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), '/offers-pdf/'))->count())->toBe(2);
});

it('backs off after failures, alerts once after three, and says when it works again', function () {
    $up = false;
    Http::fake(function (Request $request) use (&$up) {
        return str_ends_with($request->url(), '/robots.txt') ? Http::response('', 404) : ($up ? Http::response($this->pdf) : Http::response('', 404));
    });
    $source = ($this->source)();

    foreach (range(1, 4) as $_) {
        FetchBrochureSource::dispatchSync($source->id);
    }
    $source->refresh();
    expect($source->consecutive_failures)->toBe(4)
        ->and($source->last_error)->toContain('HTTP 404')
        ->and($source->failure_alerted_at)->not->toBeNull()
        ->and(now()->diffInMinutes($source->next_check_at))->toBeBetween(430.0, 530.0) // 60 × 2³, ±10 %
        ->and($this->admin->notifications()->count())->toBe(1);

    $up = true;
    FetchBrochureSource::dispatchSync($source->id);
    expect($source->refresh()->consecutive_failures)->toBe(0)
        ->and($source->failure_alerted_at)->toBeNull()
        ->and($this->admin->notifications()->pluck('data')->pluck('title')->all())->toContain('Flyer source working again: Lulu Hypermarket');
});

it('never fetches without the store\'s permission, nor from private addresses', function () {
    Http::fake();
    $this->lulu->update(['content_permission' => ContentPermission::Unknown]);
    $blocked = ($this->source)();
    FetchBrochureSource::dispatchSync($blocked->id);
    expect($blocked->fetches()->sole()->outcome)->toBe('blocked');

    $this->lulu->update(['content_permission' => ContentPermission::Granted]);
    $internal = ($this->source)(['url' => 'http://127.0.0.1:8108/collections']);
    FetchBrochureSource::dispatchSync($internal->id);
    expect($internal->fetches()->sole()->error)->toContain('not a public address');

    Http::assertNothingSent();
    expect(Brochure::count())->toBe(0);
});

it('shows staff which stores are due, and settings editors their sources', function () {
    ($this->source)(['consecutive_failures' => 2, 'last_error' => 'HTTP 404']);
    Auth::forgetGuards();
    Pest\Laravel\actingAs($this->admin, 'admin');

    Pest\Livewire\livewire(FlyerCollection::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->lulu]) // weekly, and no flyer covers today
        ->assertSee('1 failing');
    Pest\Livewire\livewire(BrochureSourcesRelationManager::class, [
        'ownerRecord' => $this->lulu,
        'pageClass' => EditChain::class,
    ])->assertOk()->assertSee('Weekly flyer')->assertSee('2 failed');
});

it('queues due sources and warns once when a weekly store goes quiet', function () {
    Http::fake();
    $source = ($this->source)(['next_check_at' => now()->subMinute()]);
    ($this->source)(['name' => 'Later', 'next_check_at' => now()->addHour()]);
    $source->forceFill(['last_new_at' => now()->subDays(10)])->save();

    expect(app(CheckSources::class)->stale())->toBe(1)
        ->and(app(CheckSources::class)->stale())->toBe(0)
        ->and($this->admin->notifications()->count())->toBe(1);

    Bus::fake();
    expect(app(CheckSources::class)->due())->toBe(1);
    Bus::assertDispatched(FetchBrochureSource::class, fn ($job) => $job->sourceId === $source->id);
});
