<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Store pages and the store directory the browser uses for radius and "open now". */
class ChainController extends Controller
{
    public function index(): JsonResponse
    {
        $chains = Chain::query()->where('is_active', true)->orderBy('slug')->get();

        return response()->json($chains->map(fn (Chain $chain) => [
            'id' => $chain->id,
            'name' => $chain->name,
            'slug' => $chain->slug,
            'format' => $chain->format->value,
        ]))->setCache(['public' => true, 'max_age' => 3600, 's_maxage' => 3600]);
    }

    public function show(string $slug): JsonResponse
    {
        $chain = Chain::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['retailGroup', 'branches' => fn ($q) => $q->where('is_active', true)->with(['region', 'hours'])])
            ->firstOrFail();

        return response()->json([
            'id' => $chain->id,
            'name' => $chain->name,
            'slug' => $chain->slug,
            'format' => $chain->format->value,
            'website' => $chain->website,
            'group' => $chain->retailGroup?->name,
            'branches' => $chain->branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'slug' => $branch->slug,
                'area' => $branch->region?->name,
                'address' => $branch->getTranslation('address', 'en', false) ?: null,
                'lat' => $branch->location->getLatitude(),
                'lng' => $branch->location->getLongitude(),
                'phone' => $branch->phone,
                'hours' => $branch->hours->map(fn ($h) => ['weekday' => $h->weekday, 'opens' => substr($h->opens_at, 0, 5), 'closes' => substr($h->closes_at, 0, 5)]),
            ]),
        ])->setCache(['public' => true, 'max_age' => 600, 's_maxage' => 600, 'stale_while_revalidate' => 3600]);
    }
}
