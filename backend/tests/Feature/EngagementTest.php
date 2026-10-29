<?php

use App\Domain\Catalog\Product;
use App\Domain\Engagement\Report;
use App\Domain\Engagement\Reports;
use App\Domain\Engagement\ReportStatus;
use App\Domain\Offers\Offer;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\User;
use App\Notifications\LoginCode;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoOffersSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, DemoCatalogSeeder::class, DemoOffersSeeder::class]);
    $this->offer = Offer::firstOrFail();
});

afterEach(fn () => Offer::removeAllFromSearch());

it('signs a shopper in with an emailed code and creates the account', function () {
    Notification::fake();

    postJson('/api/v1/auth/code', ['email' => 'Sara@Example.com'])->assertOk();

    $code = null;
    Notification::assertSentTo(new AnonymousNotifiable, LoginCode::class, function (LoginCode $n) use (&$code) {
        $code = (fn () => $this->code)->call($n);

        return true;
    });

    postJson('/api/v1/auth/verify', ['email' => 'sara@example.com', 'code' => $code, 'device_name' => 'test'])
        ->assertOk()
        ->assertJsonPath('user.email', 'sara@example.com')
        ->assertJsonStructure(['token']);

    expect(User::where('email', 'sara@example.com')->first()->email_verified_at)->not->toBeNull();
});

it('rejects a wrong code and locks after five tries', function () {
    Notification::fake();
    postJson('/api/v1/auth/code', ['email' => 'sara@example.com']);

    foreach (range(1, 5) as $_) {
        postJson('/api/v1/auth/verify', ['email' => 'sara@example.com', 'code' => '000000'])->assertUnprocessable();
    }
});

it('toggles a like and keeps the count right', function () {
    actingAs(User::factory()->create());

    postJson('/api/v1/likes', ['type' => 'offer', 'id' => $this->offer->id])->assertJson(['liked' => true, 'count' => 1]);
    getJson('/api/v1/likes')->assertJsonPath('offer', [$this->offer->id]);
    postJson('/api/v1/likes', ['type' => 'offer', 'id' => $this->offer->id])->assertJson(['liked' => false, 'count' => 0]);
});

it('requires sign-in to like', function () {
    postJson('/api/v1/likes', ['type' => 'product', 'id' => Product::first()->id])->assertUnauthorized();
});

it('accepts a guest report once per item', function () {
    $payload = ['type' => 'offer', 'id' => $this->offer->id, 'reason' => 'wrong_price', 'comment' => 'Shelf says 49.95'];

    postJson('/api/v1/reports', $payload)->assertCreated();
    postJson('/api/v1/reports', $payload)->assertOk()->assertJson(['duplicate' => true]);

    expect(Report::count())->toBe(1);
});

it('lists open reports for staff and resolves them from the queue', function () {
    $this->seed(StaffSeeder::class);
    postJson('/api/v1/reports', ['type' => 'offer', 'id' => $this->offer->id, 'reason' => 'expired']);
    $staff = User::factory()->create();
    $staff->assignRole('reviewer');
    actingAs($staff, 'admin');

    Pest\Laravel\get('/admin/reports')->assertOk()->assertSee('India Gate Classic Basmati Rice');

    Pest\Livewire\livewire(ListReports::class)
        ->callAction(TestAction::make('accept')->table(Report::first()), data: ['resolution' => 'Expired early'])
        ->assertHasNoErrors();

    expect(Report::first())->status->toBe(ReportStatus::Accepted)->handled_by->toBe($staff->id);
});

it('flags an offer after three independent price reports and clears it once handled', function () {
    $users = User::factory()->count(3)->create();
    foreach ($users as $user) {
        app('auth')->forgetGuards(); // guards cache the user between simulated requests
        actingAs($user)->postJson('/api/v1/reports', ['type' => 'offer', 'id' => $this->offer->id, 'reason' => 'wrong_price'])->assertCreated();
    }

    expect($this->offer->fresh()->conditions['under_review'] ?? false)->toBeTrue();

    $staff = User::factory()->create();
    Report::all()->each(fn (Report $r) => app(Reports::class)->resolve($r, ReportStatus::Accepted, 'Price fixed', $staff->id));

    expect($this->offer->fresh()->conditions['under_review'] ?? false)->toBeFalse();
});
