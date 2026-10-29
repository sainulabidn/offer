<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;

/** After a passkey sign-in: the same answer as the email-code sign-in (AuthController::verify). */
class PasskeySignedIn implements PasskeyLoginResponse
{
    public function toResponse($request): JsonResponse
    {
        return response()->json(['user' => $request->user()->toProfile()]);
    }
}
