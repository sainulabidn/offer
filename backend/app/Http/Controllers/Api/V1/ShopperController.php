<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Geo\PlaceName;
use App\Domain\Settings\HomeSettings;
use App\Domain\Shopper\Affinities;
use App\Domain\Shopper\ShopperPlace;
use App\Domain\Shopper\ShopperPreference;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A signed-in shopper's settings and saved places. The browser works from its own copy and pushes
 * changes here, as with plans; the newest save wins and an older one answers 409 with the server's copy.
 */
class ShopperController extends Controller
{
    /** The place list for the location search: small, the same for everyone, and it rarely changes. */
    public function placeNames(): JsonResponse
    {
        return response()->json(PlaceName::query()->orderBy('id')->get()->map(fn (PlaceName $place) => [
            $place->type,
            $place->getTranslation('name', 'en'),
            $place->getTranslation('name', 'ar', false) ?: null,
            round($place->location->getLatitude(), 4),
            round($place->location->getLongitude(), 4),
        ]))->setCache(['public' => true, 'max_age' => 86400, 's_maxage' => 86400]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $preferences = ShopperPreference::find($request->user()->id) ?? new ShopperPreference;

        return response()->json($preferences->toApi());
    }

    public function savePreferences(Request $request, Affinities $affinities): JsonResponse
    {
        $radius = ['nullable', 'integer', 'between:1,'.HomeSettings::get('radius_max')];
        $data = $request->validate([
            'radius_km' => $radius,
            'item_radius_km' => $radius,
            'item_radius_off' => ['required', 'boolean'],
            'branch_ids' => ['present', 'array', 'max:4'],
            'branch_ids.*' => ['integer', 'min:1'],
            'pinned_branch_ids' => ['sometimes', 'array', 'max:50'],
            'pinned_branch_ids.*' => ['integer', 'min:1'],
            'personalize' => ['sometimes', 'boolean'],
            'updated_at' => ['required', 'date'],
        ]);
        $incoming = Carbon::parse($data['updated_at']);
        unset($data['updated_at']);

        $preferences = ShopperPreference::firstOrNew(['user_id' => $request->user()->id]);
        if ($preferences->client_updated_at?->greaterThan($incoming)) {
            return response()->json(['conflict' => true, 'preferences' => $preferences->toApi()], 409);
        }

        $preferences->fill([...$data, 'branch_ids' => array_values($data['branch_ids']), 'client_updated_at' => $incoming])->save();

        // Saying no takes back the agreement, so what was kept under it goes too.
        if (($data['personalize'] ?? true) === false) {
            $affinities->forget($request->user());
        }

        return response()->json($preferences->toApi());
    }

    public function activity(Request $request, Affinities $affinities): JsonResponse
    {
        return response()->json($affinities->top($request->user()));
    }

    /** Only for shoppers who agreed; the browser keeps the same numbers either way. */
    public function addActivity(Request $request, Affinities $affinities): JsonResponse
    {
        abort_unless(ShopperPreference::find($request->user()->id)?->personalize, 403, 'Activity is kept on this device only.');

        $data = $request->validate([
            'items' => ['required', 'array', 'max:'.Affinities::MAX_BATCH],
            'items.*.kind' => ['required', Rule::in(Affinities::KINDS)],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.score' => ['required', 'numeric', 'between:-100,1000'],
            'items.*.at' => ['required', 'date'],
        ]);
        $affinities->add($request->user(), $data['items']);

        return response()->json(['saved' => count($data['items'])]);
    }

    public function forgetActivity(Request $request, Affinities $affinities): JsonResponse
    {
        $affinities->forget($request->user());

        return response()->json(['deleted' => true]);
    }

    /** Everything kept about this shopper's shopping, in a file they can read or take elsewhere. */
    public function export(Request $request, Affinities $affinities): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'account' => $user->toProfile(),
            'preferences' => ShopperPreference::find($user->id)?->toApi(),
            'places' => ShopperPlace::where('user_id', $user->id)->get()->map->toApi(),
            'activity' => $affinities->top($user),
            'exported_at' => now()->toIso8601String(),
        ])->header('Content-Disposition', 'attachment; filename="offer-my-data.json"');
    }

    public function places(Request $request): JsonResponse
    {
        $places = ShopperPlace::where('user_id', $request->user()->id)->orderByDesc('is_default')->orderBy('created_at')->get();

        return response()->json($places->map->toApi());
    }

    public function savePlace(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $id), 404);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'kind' => ['required', Rule::in(ShopperPlace::KINDS)],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'area' => ['nullable', 'string', 'max:120'],
            'radius_km' => ['nullable', 'integer', 'between:1,'.HomeSettings::get('radius_max')],
            'is_default' => ['sometimes', 'boolean'],
            'is_auto' => ['sometimes', 'boolean'],
            'use_score' => ['sometimes', 'numeric', 'min:0'],
        ]);
        $user = $request->user();

        $place = ShopperPlace::find($id);
        abort_if($place && $place->user_id !== $user->id, 404);
        if (! $place && ShopperPlace::where('user_id', $user->id)->count() >= ShopperPlace::MAX_PER_SHOPPER) {
            throw ValidationException::withMessages(['label' => 'You can save up to '.ShopperPlace::MAX_PER_SHOPPER.' places. Delete one first.']);
        }

        $place ??= new ShopperPlace(['id' => $id, 'user_id' => $user->id]);
        DB::transaction(function () use ($place, $data, $user) {
            // Only one default per shopper; choosing one yourself also stops the automatic choice.
            if ($data['is_default'] ?? false) {
                ShopperPlace::where('user_id', $user->id)->whereKeyNot($place->id)->update(['is_default' => false, 'is_auto' => false]);
            }

            $place->fill([
                'label' => $data['label'],
                'kind' => $data['kind'],
                'location' => ShopperPlace::pointAt((float) $data['lat'], (float) $data['lng']),
                'area_name' => $data['area'] ?? null,
                'radius_km' => $data['radius_km'] ?? null,
                'is_default' => $data['is_default'] ?? $place->is_default ?? false,
                'is_auto' => $data['is_auto'] ?? false,
                'use_score' => $data['use_score'] ?? $place->use_score ?? 0,
            ])->save();
        });

        return response()->json($place->toApi(), $place->wasRecentlyCreated ? 201 : 200);
    }

    public function deletePlace(Request $request, string $id): JsonResponse
    {
        ShopperPlace::where('user_id', $request->user()->id)->whereKey($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** "Delete my places": every saved place, in one tap. */
    public function deletePlaces(Request $request): JsonResponse
    {
        ShopperPlace::where('user_id', $request->user()->id)->delete();

        return response()->json(['deleted' => true]);
    }
}
