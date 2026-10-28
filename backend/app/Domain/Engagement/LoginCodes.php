<?php

namespace App\Domain\Engagement;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/** One-time 6-digit sign-in codes: valid 10 minutes, 5 attempts, only the hash is stored. */
final class LoginCodes
{
    private const int TTL_SECONDS = 600;

    private const int MAX_ATTEMPTS = 5;

    public function issue(string $email): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->key($email), ['hash' => Hash::make($code), 'attempts' => 0], self::TTL_SECONDS);

        return $code;
    }

    public function verify(string $email, string $code): bool
    {
        $entry = Cache::get($this->key($email));
        if (! $entry || $entry['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (! Hash::check($code, $entry['hash'])) {
            $entry['attempts']++;
            Cache::put($this->key($email), $entry, self::TTL_SECONDS);

            return false;
        }

        Cache::forget($this->key($email));

        return true;
    }

    private function key(string $email): string
    {
        return 'login-code:'.hash('sha256', mb_strtolower(trim($email)));
    }
}
