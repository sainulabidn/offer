<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/** Cloudflare Turnstile check for public forms. Without a secret (local development) every check passes. */
final class Turnstile
{
    public static function passes(?string $token, ?string $ip): bool
    {
        $secret = config('services.turnstile.secret');
        if (blank($secret)) {
            return true;
        }
        if (blank($token)) {
            return false;
        }

        return (bool) Http::asForm()->timeout(5)
            ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['secret' => $secret, 'response' => $token, 'remoteip' => $ip])
            ->json('success', false);
    }
}
