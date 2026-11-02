<?php

use App\Domain\Geo\ImportPlaceNames;
use App\Domain\Shopper\ShopperPlace;
use App\Domain\Shopper\ShopperPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

beforeEach(fn () => $this->user = User::factory()->create());

function place(array $overrides = []): array
{
    return ['label' => 'Home', 'kind' => 'home', 'lat' => 25.28941, 'lng' => 55.37042, 'area' => 'Al Nahda', ...$overrides];
}

it('keeps a shopper\'s preferences, the newest save winning across devices', function () {
    actingAs($this->user);
    $save = ['radius_km' => 25, 'item_radius_km' => null, 'item_radius_off' => true, 'branch_ids' => [4, 9], 'updated_at' => '2026-10-31T10:00:00+00:00'];

    putJson('/api/v1/me/preferences', $save)->assertOk()->assertJsonPath('radius_km', 25);
    getJson('/api/v1/me/preferences')->assertOk()
        ->assertJsonPath('item_radius_off', true)
        ->assertJsonPath('branch_ids', [4, 9]);

    putJson('/api/v1/me/preferences', [...$save, 'radius_km' => 5, 'updated_at' => '2026-10-31T09:00:00+00:00'])
        ->assertConflict()
        ->assertJsonPath('preferences.radius_km', 25);

    putJson('/api/v1/me/preferences', [...$save, 'radius_km' => 5, 'updated_at' => '2026-10-31T11:00:00+00:00'])
        ->assertOk()
        ->assertJsonPath('radius_km', 5);
});

it('answers with empty preferences before anything is saved', function () {
    actingAs($this->user);

    getJson('/api/v1/me/preferences')->assertOk()->assertJsonPath('radius_km', null)->assertJsonPath('branch_ids', []);
});

it('rejects a radius over the site limit and more than four stores', function () {
    actingAs($this->user);
    $save = ['item_radius_off' => false, 'branch_ids' => [], 'updated_at' => '2026-10-31T10:00:00+00:00'];

    putJson('/api/v1/me/preferences', [...$save, 'radius_km' => 500])->assertUnprocessable();
    putJson('/api/v1/me/preferences', [...$save, 'branch_ids' => [1, 2, 3, 4, 5]])->assertUnprocessable();
});

it('saves a place under the id made on the device, rounded to about 110 m', function () {
    actingAs($this->user);
    $id = (string) Str::uuid();

    putJson("/api/v1/me/places/{$id}", place())->assertCreated()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('lat', 25.289)
        ->assertJsonPath('lng', 55.37);

    putJson("/api/v1/me/places/{$id}", place(['label' => 'My home']))->assertOk();

    expect(ShopperPlace::count())->toBe(1)
        ->and(ShopperPlace::first()->label)->toBe('My home');
});

it('keeps one default place per shopper', function () {
    actingAs($this->user);
    $home = (string) Str::uuid();
    $office = (string) Str::uuid();

    putJson("/api/v1/me/places/{$home}", place(['is_default' => true]))->assertCreated();
    putJson("/api/v1/me/places/{$office}", place(['label' => 'Office', 'kind' => 'work', 'is_default' => true]))->assertCreated();

    expect(ShopperPlace::find($home)->is_default)->toBeFalse()
        ->and(ShopperPlace::find($office)->is_default)->toBeTrue();
});

it('treats a default the shopper chose as theirs, not the automatic one', function () {
    actingAs($this->user);
    $id = (string) Str::uuid();
    ShopperPlace::create(['id' => $id, 'user_id' => $this->user->id, 'label' => 'Al Nahda', 'kind' => 'other', 'location' => ShopperPlace::pointAt(25.28, 55.37), 'is_default' => true, 'is_auto' => true]);

    putJson("/api/v1/me/places/{$id}", place(['is_default' => true]))->assertOk()->assertJsonPath('is_auto', false);
});

it('limits how many places a shopper can save', function () {
    actingAs($this->user);
    foreach (range(1, ShopperPlace::MAX_PER_SHOPPER) as $i) {
        putJson('/api/v1/me/places/'.Str::uuid(), place(['label' => "Place {$i}"]))->assertCreated();
    }

    putJson('/api/v1/me/places/'.Str::uuid(), place())->assertUnprocessable();
});

it('keeps shoppers\' places apart', function () {
    actingAs($this->user);
    $id = (string) Str::uuid();
    putJson("/api/v1/me/places/{$id}", place())->assertCreated();

    actingAs($other = User::factory()->create());
    putJson("/api/v1/me/places/{$id}", place(['label' => 'Stolen']))->assertNotFound();
    delete("/api/v1/me/places/{$id}")->assertOk();
    getJson('/api/v1/me/places')->assertOk()->assertJsonCount(0);

    expect(ShopperPlace::find($id)->label)->toBe('Home');
});

it('deletes every saved place at once', function () {
    actingAs($this->user);
    putJson('/api/v1/me/places/'.Str::uuid(), place())->assertCreated();
    putJson('/api/v1/me/places/'.Str::uuid(), place())->assertCreated();

    delete('/api/v1/me/places')->assertOk();

    expect(ShopperPlace::count())->toBe(0);
});

it('needs a signed-in shopper', function () {
    getJson('/api/v1/me/preferences')->assertUnauthorized();
    getJson('/api/v1/me/places')->assertUnauthorized();
});

it('deletes a shopper\'s data with their account', function () {
    ShopperPreference::create(['user_id' => $this->user->id]);
    ShopperPlace::create(['id' => (string) Str::uuid(), 'user_id' => $this->user->id, 'label' => 'Home', 'location' => ShopperPlace::pointAt(25.28, 55.37)]);

    $this->user->delete();

    expect(ShopperPreference::count())->toBe(0)
        ->and(ShopperPlace::count())->toBe(0);
});

it('loads the UAE place list and serves it for the location search', function () {
    expect(app(ImportPlaceNames::class)())->toBeGreaterThan(1000);

    $places = collect(getJson('/api/v1/places')->assertOk()->assertHeader('Cache-Control')->json());

    expect($places->pluck(0)->unique()->sort()->values()->all())->toBe(['area', 'mall', 'station'])
        ->and($places->firstWhere(1, 'Deira'))->not->toBeNull();

    $second = app(ImportPlaceNames::class)();
    expect(DB::table('place_names')->count())->toBe($second);
});
