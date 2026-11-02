<?php

use App\Domain\Shopper\Affinities;
use App\Domain\Shopper\ShopperAffinity;
use App\Domain\Shopper\ShopperPlace;
use App\Domain\Shopper\ShopperPreference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-02 09:00'));
    $this->user = User::factory()->create();
    actingAs($this->user);
});

function agree(User $user): void
{
    ShopperPreference::create(['user_id' => $user->id, 'personalize' => true]);
}

function item(string $kind, int $id, float $score, string $at = '2026-11-02T09:00:00Z'): array
{
    return compact('kind', 'id', 'score', 'at');
}

it('keeps activity only for shoppers who agreed', function () {
    postJson('/api/v1/me/activity', ['items' => [item('group', 1, 5)]])->assertForbidden();

    expect(ShopperAffinity::count())->toBe(0);
});

it('adds up activity, fading what was there', function () {
    agree($this->user);
    postJson('/api/v1/me/activity', ['items' => [item('branch', 1, 8, '2026-10-03T09:00:00Z')]])->assertOk();
    postJson('/api/v1/me/activity', ['items' => [item('branch', 1, 1)]])->assertOk();

    // 8 halves over the 30 days between the two, plus the new 1.
    expect(ShopperAffinity::first())->score->toEqualWithDelta(5, 0.001)->events->toBe(2);
});

it('uses the shorter half-life for items', function () {
    agree($this->user);
    postJson('/api/v1/me/activity', ['items' => [item('group', 1, 8, '2026-10-12T09:00:00Z')]])->assertOk();
    postJson('/api/v1/me/activity', ['items' => [item('group', 1, 0)]])->assertOk();

    expect(ShopperAffinity::first()->score)->toEqualWithDelta(4, 0.001);
});

it('merges repeats in one batch, as a guest history would send them', function () {
    agree($this->user);

    postJson('/api/v1/me/activity', ['items' => [item('group', 1, 2), item('group', 1, 3)]])->assertOk();

    expect(ShopperAffinity::first()->score)->toEqual(5.0);
});

it('does not trust a clock set in the future', function () {
    agree($this->user);

    postJson('/api/v1/me/activity', ['items' => [item('group', 1, 2, '2030-01-01T00:00:00Z')]])->assertOk();

    expect(Carbon::parse(ShopperAffinity::first()->touched_at)->isFuture())->toBeFalse();
});

it('returns the strongest per kind, to a new device', function () {
    agree($this->user);
    app(Affinities::class)->add($this->user, array_map(fn (int $i) => item('product', $i, $i), range(1, 120)));

    $activity = collect(getJson('/api/v1/me/activity')->assertOk()->json());

    expect($activity)->toHaveCount(100)
        ->and($activity->pluck('id'))->toContain(120)->not->toContain(5);
});

it('rejects unknown kinds and huge scores', function () {
    agree($this->user);

    postJson('/api/v1/me/activity', ['items' => [item('user', 1, 1)]])->assertUnprocessable();
    postJson('/api/v1/me/activity', ['items' => [item('group', 1, 5000)]])->assertUnprocessable();
});

it('forgets activity on request', function () {
    agree($this->user);
    postJson('/api/v1/me/activity', ['items' => [item('group', 1, 2)]])->assertOk();

    deleteJson('/api/v1/me/activity')->assertOk();

    expect(ShopperAffinity::count())->toBe(0);
});

it('deletes what was kept when a shopper says no', function () {
    agree($this->user);
    postJson('/api/v1/me/activity', ['items' => [item('group', 1, 2)]])->assertOk();

    putJson('/api/v1/me/preferences', ['item_radius_off' => false, 'branch_ids' => [], 'personalize' => false, 'updated_at' => '2026-11-02T09:00:00Z'])->assertOk();

    expect(ShopperAffinity::count())->toBe(0);
});

it('keeps pinned stores across devices', function () {
    putJson('/api/v1/me/preferences', ['item_radius_off' => false, 'branch_ids' => [], 'pinned_branch_ids' => [3, 9], 'updated_at' => '2026-11-02T09:00:00Z'])->assertOk();

    getJson('/api/v1/me/preferences')->assertJsonPath('pinned_branch_ids', [3, 9]);
});

it('remembers an automatic default as automatic, and how used a place is', function () {
    $id = (string) Str::uuid();

    putJson("/api/v1/me/places/{$id}", ['label' => 'Al Nahda', 'kind' => 'other', 'lat' => 25.29, 'lng' => 55.37, 'is_default' => true, 'is_auto' => true, 'use_score' => 4.5])
        ->assertCreated()
        ->assertJsonPath('is_auto', true)
        ->assertJsonPath('use_score', 4.5);

    expect(ShopperPlace::find($id)->is_default)->toBeTrue();
});

it('exports what is kept about a shopper, and only theirs', function () {
    agree($this->user);
    postJson('/api/v1/me/activity', ['items' => [item('group', 1, 2)]])->assertOk();
    putJson('/api/v1/me/places/'.Str::uuid(), ['label' => 'Home', 'kind' => 'home', 'lat' => 25.29, 'lng' => 55.37])->assertCreated();
    agree($other = User::factory()->create());
    app(Affinities::class)->add($other, [item('group', 2, 9)]);

    $export = getJson('/api/v1/me/export')->assertOk()->assertHeader('Content-Disposition')->json();

    expect($export['account']['email'])->toBe($this->user->email)
        ->and($export['places'])->toHaveCount(1)
        ->and($export['activity'])->toHaveCount(1)
        ->and($export['activity'][0]['id'])->toBe(1);
});

it('needs a signed-in shopper', function () {
    app('auth')->forgetGuards();

    getJson('/api/v1/me/activity')->assertUnauthorized();
});
