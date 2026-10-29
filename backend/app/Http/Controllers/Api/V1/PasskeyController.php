<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkey;

/**
 * The shopper's passkeys, for the account page. Adding, removing and signing in with one use the
 * laravel/passkeys controllers (routes/api.php).
 */
class PasskeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->passkeys()->latest()->get()->map(fn (Passkey $passkey) => [
            'id' => $passkey->id,
            'name' => $passkey->name,
            // From the authenticator's AAGUID, e.g. "iCloud Keychain" or "Google Password Manager".
            'authenticator' => $passkey->authenticator,
            'created_at' => $passkey->created_at?->toIso8601String(),
            'last_used_at' => $passkey->last_used_at?->toIso8601String(),
        ]));
    }
}
