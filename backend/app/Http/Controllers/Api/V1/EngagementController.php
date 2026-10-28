<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Engagement\Likes;
use App\Domain\Engagement\ReportReason;
use App\Domain\Engagement\Reports;
use App\Http\Controllers\Controller;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EngagementController extends Controller
{
    public function likes(Request $request, Likes $likes): JsonResponse
    {
        return response()->json($likes->of($request->user()));
    }

    public function toggleLike(Request $request, Likes $likes): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Likes::TYPES))],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($likes->toggle($request->user(), $likes->target($data['type'], $data['id'])));
    }

    /** Guests may report too; they're identified only by a salted hash of IP + browser. */
    public function report(Request $request, Reports $reports): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Reports::TYPES))],
            'id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
            'turnstile' => ['nullable', 'string'],
        ]);
        $user = $request->user('sanctum');
        if (! $user && ! Turnstile::passes($data['turnstile'] ?? null, $request->ip())) {
            throw ValidationException::withMessages(['turnstile' => 'Please confirm you are not a robot.']);
        }

        $guestHash = hash_hmac('sha256', $request->ip().'|'.$request->userAgent(), (string) config('app.key'));
        $report = $reports->file(
            $reports->target($data['type'], $data['id']),
            ReportReason::from($data['reason']),
            $data['comment'] ?? null,
            $user?->id,
            $guestHash,
        );

        return response()->json(['received' => true, 'duplicate' => $report === null], $report ? 201 : 200);
    }
}
