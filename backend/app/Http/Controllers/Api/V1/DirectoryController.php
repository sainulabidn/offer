<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Geo\Region;
use App\Domain\Retail\Branch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The store directory the website uses for "near me": every active branch with coordinates, which
 * brochures are live at which branches, and the area picker. Small, cached, and the same for everyone.
 */
class DirectoryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = now(config('app.market_timezone'))->toDateString();

        $branches = Branch::query()->where('is_active', true)
            ->whereHas('chain', fn ($q) => $q->where('is_active', true))
            ->with(['chain', 'region'])
            ->get();

        $brochures = Brochure::query()
            ->whereIn('status', [BrochureStatus::Published, BrochureStatus::Scheduled])
            ->whereDate('valid_to', '>=', $today)
            ->with('branches:id')
            ->get(['id', 'chain_id', 'valid_from', 'valid_to']);

        $areas = Region::query()->whereNotNull('centroid')->with('parent')->orderBy('type')->orderBy('sort')->get();

        return response()->json([
            'branches' => $branches->map(fn (Branch $b) => [
                'id' => $b->id,
                'chain_id' => $b->chain_id,
                'chain' => $b->chain->name,
                'name' => $b->name,
                'area' => $b->region?->name,
                'lat' => round($b->location->getLatitude(), 5),
                'lng' => round($b->location->getLongitude(), 5),
            ]),
            'brochures' => $brochures->map(fn (Brochure $b) => [
                'id' => $b->id,
                'chain_id' => $b->chain_id,
                'valid_from' => $b->valid_from->toDateString(),
                'valid_to' => $b->valid_to->toDateString(),
                'branch_ids' => $b->branches->modelKeys(),
            ]),
            'areas' => $areas->map(fn (Region $r) => [
                'slug' => $r->slug,
                'name' => $r->parent ? "{$r->name}, {$r->parent->name}" : $r->name,
                'lat' => round($r->centroid->getLatitude(), 4),
                'lng' => round($r->centroid->getLongitude(), 4),
            ]),
        ])->setCache(['public' => true, 'max_age' => 300, 's_maxage' => 600, 'stale_while_revalidate' => 3600]);
    }
}
