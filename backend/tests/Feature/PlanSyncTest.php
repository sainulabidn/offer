<?php

use App\Domain\Plans\Plan;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

$plan = fn (string $at, array $items = [['key' => 'v:1', 'name' => 'Rice 5 kg', 'quantity' => 2, 'match' => ['variantId' => 1]]]) => [
    'name' => 'Weekly shop',
    'items' => $items,
    'settings' => ['radiusKm' => 10, 'maxStores' => 2],
    'updated_at' => $at,
];

beforeEach(function () {
    $this->id = (string) Str::uuid();
    $this->owner = User::factory()->create(['name' => 'Sara']);
});

it('saves a plan made on the device and lists it on another', function () use ($plan) {
    actingAs($this->owner);

    putJson("/api/v1/plans/{$this->id}", $plan('2026-10-29T10:00:00Z'))->assertCreated();

    getJson('/api/v1/plans')
        ->assertJsonPath('0.id', $this->id)
        ->assertJsonPath('0.items.0.quantity', 2)
        ->assertJsonPath('0.settings.maxStores', 2);
});

it('keeps the newest version and returns it when an older save arrives', function () use ($plan) {
    actingAs($this->owner);
    putJson("/api/v1/plans/{$this->id}", $plan('2026-10-29T10:05:00Z', []))->assertCreated();

    putJson("/api/v1/plans/{$this->id}", $plan('2026-10-29T10:00:00Z'))
        ->assertStatus(409)
        ->assertJsonPath('plan.items', []);
});

it('shares a plan so a family member can view, join and edit it', function () use ($plan) {
    actingAs($this->owner);
    putJson("/api/v1/plans/{$this->id}", $plan('2026-10-29T10:00:00Z'));
    $token = postJson("/api/v1/plans/{$this->id}/share")->assertOk()->json('token');

    // Anyone with the link can read it.
    app('auth')->forgetGuards();
    getJson("/api/v1/shared-plans/{$token}")->assertOk()->assertJsonPath('owner', 'Sara')->assertJsonPath('items.0.name', 'Rice 5 kg');

    $partner = User::factory()->create();
    actingAs($partner);
    postJson("/api/v1/shared-plans/{$token}/join")->assertOk()->assertJsonPath('is_owner', false);
    putJson("/api/v1/plans/{$this->id}", $plan('2026-10-29T11:00:00Z', []))->assertOk();

    expect(Plan::find($this->id)->items)->toBe([])
        ->and(Plan::find($this->id)->updated_by)->toBe($partner->id);
});

it('hides other people\'s plans', function () use ($plan) {
    actingAs($this->owner);
    putJson("/api/v1/plans/{$this->id}", $plan('2026-10-29T10:00:00Z'));

    app('auth')->forgetGuards();
    actingAs(User::factory()->create());
    putJson("/api/v1/plans/{$this->id}", $plan('2026-10-29T12:00:00Z', []))->assertNotFound();
    deleteJson("/api/v1/plans/{$this->id}")->assertNotFound();
    expect(Plan::find($this->id)->items)->toHaveCount(1);
});
