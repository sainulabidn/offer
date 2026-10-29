<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\Support\VirtualAuthenticator;

// The website (FRONTEND_URL, localhost:4321 here) runs the ceremony; the session holds the challenge.
beforeEach(function () {
    $this->withHeaders(['Origin' => 'http://localhost:4321', 'Referer' => 'http://localhost:4321/account']);
    $this->user = User::factory()->create(['email' => 'sara@example.com']);
    $this->device = new VirtualAuthenticator;
});

function addPasskey(User $user, VirtualAuthenticator $device, string $name = 'Sara’s phone')
{
    signInAs($user);
    $options = test()->getJson('/api/v1/passkeys/options')->assertOk()->json('options');

    return test()->postJson('/api/v1/passkeys', ['name' => $name, 'credential' => $device->create($options)]);
}

// Guards keep the user they resolved for the whole test; start fresh like a new request would.
function signInAs(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'web');
}

function signOut(): void
{
    Auth::guard('web')->logout();
    Auth::forgetGuards();
}

it('adds a passkey to a signed-in account and lists it', function () {
    addPasskey($this->user, $this->device)->assertOk()->assertJson(['name' => 'Sara’s phone']);

    $this->getJson('/api/v1/passkeys')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'Sara’s phone')
        ->assertJsonPath('0.last_used_at', null);
});

it('signs in with the passkey, without an email or code', function () {
    addPasskey($this->user, $this->device)->assertOk();
    signOut();

    $options = $this->getJson('/api/v1/auth/passkey/options')->assertOk()->json('options');
    expect($options['allowCredentials'] ?? [])->toBe([]) // any passkey on the device: no email to type
        ->and($options['userVerification'])->toBe('required');

    $this->postJson('/api/v1/auth/passkey', ['credential' => $this->device->get($options), 'remember' => true])
        ->assertOk()
        ->assertJsonPath('user.email', 'sara@example.com');

    $this->assertAuthenticatedAs($this->user, 'web');
    expect($this->user->passkeys()->first()->last_used_at)->not->toBeNull();
});

it('refuses a reused challenge and a passkey made for another site', function () {
    addPasskey($this->user, $this->device)->assertOk();
    signOut();

    $options = $this->getJson('/api/v1/auth/passkey/options')->json('options');
    $answer = $this->device->get($options);
    $this->postJson('/api/v1/auth/passkey', ['credential' => $answer])->assertOk();
    signOut();
    $this->postJson('/api/v1/auth/passkey', ['credential' => $answer])->assertUnprocessable(); // replay
    $this->assertGuest('web');

    $phishing = new VirtualAuthenticator('https://offer-deals.example');
    addPasskey($this->user, $phishing)->assertUnprocessable();
});

it('needs a signed-in account to add a passkey, and only removes your own', function () {
    $this->getJson('/api/v1/passkeys/options')->assertUnauthorized();

    addPasskey($this->user, $this->device)->assertOk();
    $passkey = $this->user->passkeys()->firstOrFail();

    signInAs(User::factory()->create());
    $this->deleteJson("/api/v1/passkeys/{$passkey->id}")->assertForbidden();

    signInAs($this->user);
    $this->deleteJson("/api/v1/passkeys/{$passkey->id}")->assertSuccessful();
    expect($this->user->passkeys()->count())->toBe(0);
});
