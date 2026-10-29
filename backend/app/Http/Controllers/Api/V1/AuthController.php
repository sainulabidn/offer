<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Engagement\LoginCodes;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\LoginCode;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Passwordless shopper sign-in with an emailed code.
 * The website gets a session cookie (Sanctum SPA); apps pass device_name and get a token instead.
 */
class AuthController extends Controller
{
    public function sendCode(Request $request, LoginCodes $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255'], 'turnstile' => ['nullable', 'string']]);
        if (! Turnstile::passes($data['turnstile'] ?? null, $request->ip())) {
            throw ValidationException::withMessages(['turnstile' => 'Please confirm you are not a robot.']);
        }

        Notification::route('mail', $data['email'])->notify(new LoginCode($codes->issue($data['email'])));

        // Same answer whether or not the address has an account.
        return response()->json(['sent' => true]);
    }

    public function verify(Request $request, LoginCodes $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        if (! $codes->verify($data['email'], $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired.']);
        }

        $email = mb_strtolower(trim($data['email']));
        $user = User::firstOrCreate(['email' => $email], [
            'name' => Str::before($email, '@'),
            'password' => Str::password(32),
        ]);
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        if (filled($data['device_name'] ?? null)) {
            return response()->json(['user' => $this->profile($user), 'token' => $user->createToken($data['device_name'])->plainTextToken]);
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return response()->json(['user' => $this->profile($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user()->currentAccessToken() && method_exists($request->user()->currentAccessToken(), 'delete')) {
            $request->user()->currentAccessToken()->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['signed_out' => true]);
    }

    private function profile(User $user): array
    {
        return $user->toProfile();
    }
}
