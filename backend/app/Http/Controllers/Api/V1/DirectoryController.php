<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Geo\Region;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Settings\HomeSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The store directory the website uses for "near me": every active chain and branch with coordinates,
 * which brochures are live at which branches, the area picker, and the home page settings. Small,
 * cached, and the same for everyone.
 */
class DirectoryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = now(config('app.market_timezone'))->toDateString();
        $weekday = now(config('app.market_timezone'))->dayOfWeek;

        $chains = Chain::query()->where('is_active', true)->get();

        $branches = Branch::query()->where('is_active', true)
            ->whereIn('chain_id', $chains->modelKeys())
            ->with(['chain', 'region.parent', 'hours', 'hourExceptions' => fn ($query) => $query->whereRaw('period @> ?::date', [$today])])
            ->get();

        $brochures = Brochure::query()
            ->select(['id', 'chain_id', 'valid_from', 'valid_to'])
            ->whereIn('status', [BrochureStatus::Published, BrochureStatus::Scheduled])
            ->whereDate('valid_to', '>=', $today)
            ->with('branches:id')
            ->withCount(['offers' => fn ($query) => $query->live($today)])
            ->get();

        $areas = Region::query()->whereNotNull('centroid')->with('parent')->orderBy('type')->orderBy('sort')->get();

        return response()->json([
            'chains' => $chains->map(fn (Chain $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'grade' => (int) $c->grade,
                'group_id' => $c->retail_group_id,
                'logo' => $c->logoUrl(),
            ])->values(),
            'branches' => $branches->map(fn (Branch $b) => [
                'id' => $b->id,
                'chain_id' => $b->chain_id,
                'chain' => $b->chain->name,
                'name' => $b->name,
                'slug' => $b->slug,
                'area' => $b->region?->name,
                'emirate' => ($b->region?->parent ?? $b->region)?->slug,
                'lat' => round($b->location->getLatitude(), 5),
                'lng' => round($b->location->getLongitude(), 5),
                ...$this->hours($b, $weekday),
            ]),
            'brochures' => $brochures->map(fn (Brochure $b) => [
                'id' => $b->id,
                'chain_id' => $b->chain_id,
                'valid_from' => $b->valid_from->toDateString(),
                'valid_to' => $b->valid_to->toDateString(),
                'branch_ids' => $b->branches->modelKeys(),
                'offers' => $b->offers_count,
            ]),
            'areas' => $areas->map(fn (Region $r) => [
                'id' => $r->id,
                'slug' => $r->slug,
                'name' => $r->parent ? "{$r->name}, {$r->parent->name}" : $r->name,
                'lat' => round($r->centroid->getLatitude(), 4),
                'lng' => round($r->centroid->getLongitude(), 4),
            ]),
            'settings' => HomeSettings::all(),
        ])->setCache(['public' => true, 'max_age' => 300, 's_maxage' => 600, 'stale_while_revalidate' => 3600]);
    }

    /** The weekly hours for "open now", with today's holiday or Ramadan timing in place of today's. */
    private function hours(Branch $branch, int $weekday): array
    {
        $exception = $branch->hourExceptions->first();
        $hours = $branch->hours
            ->reject(fn ($h) => $exception && $h->weekday === $weekday)
            ->map(fn ($h) => ['weekday' => $h->weekday, 'opens' => substr($h->opens_at, 0, 5), 'closes' => substr($h->closes_at, 0, 5)])
            ->values();

        if ($exception && ! $exception->is_closed && $exception->opens_at && $exception->closes_at) {
            $hours->push(['weekday' => $weekday, 'opens' => substr($exception->opens_at, 0, 5), 'closes' => substr($exception->closes_at, 0, 5)]);
        }

        return ['hours' => $hours->all(), 'closed_today' => (bool) $exception?->is_closed];
    }
}
