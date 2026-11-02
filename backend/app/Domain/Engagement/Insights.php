<?php

namespace App\Domain\Engagement;

use App\Domain\Catalog\Product;
use App\Domain\Retail\Chain;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** What the public counts say: most viewed, rising items and whether personal ranking helps. */
final class Insights
{
    /** A view counts once, a click twice, a plan three times: each shows more intent than the last. */
    private const string INTEREST = 'views + 2 * clicks + 3 * plans';

    private const int RECENT_DAYS = 3;

    private const int EARLIER_DAYS = 14;

    /** Below this, a few clicks would look like a trend. */
    private const int MIN_RECENT_INTEREST = 5;

    /** Each group needs this many home visits before a comparison means anything. */
    public const int MIN_COHORT_VISITS = 1000;

    /** @return list<array{id: int, name: string, views: int, clicks: int, plans: int, directions: int}> */
    public function mostViewed(string $kind, int $days, ?int $regionId, int $limit = 15): array
    {
        $rows = $this->counts($kind, $days, $regionId)
            ->select('target_id')
            ->selectRaw('sum(views) as views, sum(clicks) as clicks, sum(plans) as plans, sum(directions) as directions')
            ->groupBy('target_id')
            ->orderByRaw('sum('.self::INTEREST.') desc')
            ->limit($limit)
            ->get();

        $names = match ($kind) {
            'product' => Product::whereKey($rows->pluck('target_id'))->get()->mapWithKeys(fn (Product $product) => [$product->id => $product->name]),
            'chain' => Chain::whereKey($rows->pluck('target_id'))->pluck('name', 'id'),
        };

        return $rows->map(fn (object $row) => [
            'id' => (int) $row->target_id,
            'name' => $names[$row->target_id] ?? "#{$row->target_id}",
            'views' => (int) $row->views,
            'clicks' => (int) $row->clicks,
            'plans' => (int) $row->plans,
            'directions' => (int) $row->directions,
        ])->all();
    }

    /**
     * Clicks per home visit, for shoppers on the personal order and those kept on the shared order.
     *
     * @return array{personal: array{visits: int, clicks: int, rate: float}, shared: array{visits: int, clicks: int, rate: float}, enough: bool}
     */
    public function cohorts(int $days): array
    {
        $rows = $this->counts('cohort', $days, null)->selectRaw('target_id, sum(views) as visits, sum(clicks) as clicks')->groupBy('target_id')->get()->keyBy('target_id');

        $group = function (int $id) use ($rows): array {
            $visits = (int) ($rows[$id]->visits ?? 0);
            $clicks = (int) ($rows[$id]->clicks ?? 0);

            return ['visits' => $visits, 'clicks' => $clicks, 'rate' => $visits ? round($clicks / $visits, 3) : 0.0];
        };

        $personal = $group(1);
        $shared = $group(2);

        return ['personal' => $personal, 'shared' => $shared, 'enough' => min($personal['visits'], $shared['visits']) >= self::MIN_COHORT_VISITS];
    }

    /**
     * Products rising in one area: interest per day over the last days against the two weeks before.
     *
     * @return list<int> product ids, fastest rising first
     */
    public function trending(?int $regionId, int $limit = 12): array
    {
        $today = now(config('app.market_timezone'))->startOfDay();
        $recentFrom = $today->copy()->subDays(self::RECENT_DAYS - 1)->toDateString();
        $interest = self::INTEREST;

        return DB::query()
            ->fromSub(
                $this->counts('product', self::RECENT_DAYS + self::EARLIER_DAYS, $regionId)
                    ->select('target_id')
                    ->selectRaw("coalesce(sum({$interest}) filter (where day >= ?), 0) as recent", [$recentFrom])
                    ->selectRaw("coalesce(sum({$interest}) filter (where day < ?), 0) as earlier", [$recentFrom])
                    ->groupBy('target_id'),
                'totals',
            )
            ->where('recent', '>=', self::MIN_RECENT_INTEREST)
            ->whereRaw('recent::numeric / ? > earlier::numeric / ?', [self::RECENT_DAYS, self::EARLIER_DAYS])
            ->orderByRaw('recent::numeric / ? - earlier::numeric / ? desc', [self::RECENT_DAYS, self::EARLIER_DAYS])
            ->limit($limit)
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function counts(string $kind, int $days, ?int $regionId): Builder
    {
        $since = now(config('app.market_timezone'))->subDays($days - 1)->toDateString();

        return DB::table('engagement_daily')
            ->where('kind', $kind)
            ->where('day', '>=', $since)
            ->when($regionId, fn (Builder $query) => $query->where('region_id', $regionId));
    }
}
