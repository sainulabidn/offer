<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Plans\Plan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Shopping plans across devices. The browser owns the plan and pushes it whole; the server keeps
 * the newest version (by the device's updated_at) and answers 409 with its copy when a save is older.
 */
class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $plans = Plan::query()
            ->where('user_id', $user->id)
            ->orWhereHas('members', fn ($q) => $q->whereKey($user->id))
            ->orderByDesc('client_updated_at')
            ->get();

        return response()->json($plans->map->toApi($user));
    }

    public function upsert(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $id), 404);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'items' => ['present', 'array', 'max:'.Plan::MAX_ITEMS],
            'items.*.key' => ['required', 'string', 'max:40'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.quantity' => ['required', 'integer', 'between:1,999'],
            'items.*.match.variantId' => ['nullable', 'integer'],
            'items.*.match.productGroupId' => ['nullable', 'integer'],
            'settings' => ['nullable', 'array'],
            'updated_at' => ['required', 'date'],
        ]);
        $user = $request->user();
        $incoming = Carbon::parse($data['updated_at']);

        $plan = Plan::find($id);
        if ($plan && ! $plan->canEdit($user)) {
            abort(404);
        }
        if ($plan && $plan->client_updated_at->greaterThan($incoming)) {
            return response()->json(['conflict' => true, 'plan' => $plan->toApi($user)], 409);
        }

        $plan ??= new Plan(['id' => $id, 'user_id' => $user->id]);
        $plan->fill([
            'name' => $data['name'] ?? $plan->name ?? 'My plan',
            'items' => array_values($data['items']),
            'settings' => $data['settings'] ?? [],
            'client_updated_at' => $incoming,
            'updated_by' => $user->id,
        ])->save();

        return response()->json($plan->toApi($user), $plan->wasRecentlyCreated ? 201 : 200);
    }

    /** Owners delete; members leave. */
    public function destroy(Request $request, Plan $plan): JsonResponse
    {
        $user = $request->user();
        abort_unless($plan->canEdit($user), 404);
        if ($plan->user_id === $user->id) {
            $plan->delete();
        } else {
            $plan->members()->detach($user->id);
        }

        return response()->json(['deleted' => true]);
    }

    public function share(Request $request, Plan $plan): JsonResponse
    {
        abort_unless($plan->canEdit($request->user()), 404);

        return response()->json(['token' => $plan->shareToken()]);
    }

    /** Anyone with the link can see the list (no prices are stored; the browser computes them). */
    public function shared(string $token): JsonResponse
    {
        $plan = Plan::where('share_token', $token)->with('owner')->firstOrFail();

        return response()->json([
            'name' => $plan->name,
            'owner' => $plan->owner->name,
            'items' => $plan->items ?? [],
            'updated_at' => $plan->client_updated_at->toIso8601String(),
        ])->setCache(['private' => true, 'max_age' => 30]);
    }

    /** A signed-in shopper with the link becomes a member and can edit the plan. */
    public function join(Request $request, string $token): JsonResponse
    {
        $plan = Plan::where('share_token', $token)->firstOrFail();
        $user = $request->user();
        if ($plan->user_id !== $user->id) {
            $plan->members()->syncWithoutDetaching([$user->id]);
        }

        return response()->json($plan->toApi($user));
    }
}
