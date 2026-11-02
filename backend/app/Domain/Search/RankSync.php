<?php

namespace App\Domain\Search;

use App\Domain\Catalog\CompareAs;
use App\Domain\Catalog\ProductStat;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Offers\RegularPrice;
use App\Domain\Settings\HomeSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Typesense\Client;

/**
 * The order products have for everyone: grade, popularity and best deal in one number, plus what
 * changed in the stores' usual prices. Runs every 30 minutes (search:sync-rank).
 *
 * A product's rank is saved only after all its offers took it: the rank must be equal on every offer
 * of a product, or sorting by rank stops finding that product's cheapest offer. The same goes for
 * compare_rank on every offer of one home page card: the best rank among the products on it.
 */
final class RankSync
{
    private const int BATCH = 1000;

    /** Smaller moves in rank (out of 10,000) wait for a later run, so normal view counts don't rewrite every offer. */
    private const int MIN_CHANGE = 50;

    /** A deal this far below the 90-day median scores full marks. */
    private const float BEST_DEAL_PCT = 40;

    private const string USUAL_PRICES_SYNCED = 'search:usual-prices-synced-at';

    public function __construct(private readonly Client $client) {}

    /** 0–10,000 from a grade (1 best), a popularity percentile (0–1) and the best deal_score. */
    public static function rank(int $grade, float $popularity, float $bestDeal): int
    {
        $weights = HomeSettings::get('rank_weights');
        $gradeScore = (4 - min(max($grade, 1), 4)) / 3;
        $dealScore = min(max($bestDeal, 0), self::BEST_DEAL_PCT) / self::BEST_DEAL_PCT;

        return (int) round(10000 * ($weights['grade'] * $gradeScore + $weights['popularity'] * $popularity + $weights['deal'] * $dealScore));
    }

    /** @return array{products: int, offers: int} products ranked, and offers updated in Typesense */
    public function __invoke(): array
    {
        $startedAt = now();
        $today = now(config('app.market_timezone'))->toDateString();

        $rows = $this->liveProducts($today)->map(function (object $row) {
            $row->rank = self::rank($row->grade, (float) $row->popularity, (float) $row->best_deal);
            $row->card = CompareAs::from($row->compare_as)->key($row->product_group_id, $row->product_id);

            return $row;
        });
        $known = ProductStat::query()->get()->keyBy('product_id');
        $compareRanks = $this->compareRanks($rows, $known);

        $changed = $rows->filter(function (object $row) use ($known, $compareRanks) {
            $stat = $known->get($row->product_id);

            return ! $stat || $stat->grade !== $row->grade || abs($stat->rank - $row->rank) >= self::MIN_CHANGE
                || $stat->compare_rank !== $compareRanks[$row->card];
        })->each(fn (object $row) => $row->compare_rank = $compareRanks[$row->card])->keyBy('product_id');

        ['failed' => $failed, 'offers' => $offers] = $this->push($changed, $today);

        $stats = $rows->map(function (object $row) use ($changed, $failed, $known) {
            $pushed = $changed->has($row->product_id) && ! isset($failed[$row->product_id]);
            $stat = $known->get($row->product_id);

            return [
                'product_id' => $row->product_id,
                'popularity' => (float) $row->popularity,
                'views_14d' => (int) $row->views_14d,
                'rank' => $pushed ? $row->rank : ($stat->rank ?? 0),
                'grade' => $pushed ? $row->grade : $stat?->grade,
                'compare_rank' => $pushed ? $row->compare_rank : ($stat->compare_rank ?? 0),
            ];
        });
        foreach ($stats->chunk(self::BATCH) as $chunk) {
            ProductStat::upsert($chunk->values()->all(), ['product_id'], ['popularity', 'views_14d', 'rank', 'grade', 'compare_rank']);
        }

        $this->pushUsualPrices($startedAt);

        return ['products' => $rows->count(), 'offers' => $offers];
    }

    /**
     * The rank each card should carry. A card whose products already agree on a value close to the new
     * one keeps it, so small moves don't rewrite every offer; otherwise all its products take the new one.
     *
     * @return array<string, int> card key => compare_rank
     */
    private function compareRanks(Collection $rows, Collection $known): array
    {
        return $rows->groupBy('card')->map(function (Collection $products) use ($known) {
            $best = $products->max('rank');
            $stored = $products->map(fn (object $row) => $known->get($row->product_id)?->compare_rank)->unique();

            return $stored->count() === 1 && $stored->first() !== null && abs($stored->first() - $best) < self::MIN_CHANGE ? $stored->first() : $best;
        })->all();
    }

    /** Products with live offers: grade, best deal, and how much attention they got lately (fading over 7 days). */
    private function liveProducts(string $today): Collection
    {
        return collect(DB::select(<<<'SQL'
            WITH live AS (
                SELECT v.product_id, p.product_group_id, g.compare_as, COALESCE(p.grade, g.grade, 3) AS grade, MAX(o.deal_score) AS best_deal
                FROM offers o
                JOIN product_variants v ON v.id = o.product_variant_id
                JOIN products p ON p.id = v.product_id
                JOIN product_groups g ON g.id = p.product_group_id
                WHERE o.status = 'published' AND o.valid_from <= ?::date AND o.valid_to >= ?::date
                GROUP BY v.product_id, p.product_group_id, g.compare_as, p.grade, g.grade
            ), activity AS (
                SELECT target_id AS product_id,
                       SUM(views) AS views,
                       SUM((views + 3 * clicks + 5 * plans) * POWER(0.5, (?::date - day) / 7.0)) AS heat
                FROM engagement_daily
                WHERE kind = 'product' AND day > ?::date - 14
                GROUP BY target_id
            )
            SELECT l.product_id, l.product_group_id, l.compare_as, l.grade, COALESCE(l.best_deal, 0) AS best_deal, COALESCE(a.views, 0) AS views_14d,
                   PERCENT_RANK() OVER (ORDER BY COALESCE(a.heat, 0)) AS popularity
            FROM live l
            LEFT JOIN activity a USING (product_id)
            SQL, [$today, $today, $today, $today]));
    }

    /**
     * Partial update of the changed products' live offers. Typesense answers 200 whatever happens, so
     * each result line is checked. A 404 is an offer that left search since the query, which is fine.
     *
     * @return array{failed: array<int, true>, offers: int}
     */
    private function push(Collection $changed, string $today): array
    {
        $lines = DB::table('offers as o')
            ->join('product_variants as v', 'v.id', '=', 'o.product_variant_id')
            ->whereIn('v.product_id', $changed->keys())
            ->where('o.status', OfferStatus::Published->value)
            ->whereDate('o.valid_from', '<=', $today)
            ->whereDate('o.valid_to', '>=', $today)
            ->get(['o.id', 'v.product_id']);

        $documents = $this->client->collections[(new Offer)->searchableAs()]->documents;
        $failed = [];
        foreach ($lines->chunk(self::BATCH) as $chunk) {
            $chunk = $chunk->values();
            $results = $documents->import($chunk->map(fn (object $line) => [
                'id' => (string) $line->id,
                'product_rank' => $changed[$line->product_id]->rank,
                'compare_rank' => $changed[$line->product_id]->compare_rank,
                'item_grade' => $changed[$line->product_id]->grade,
            ])->all(), ['action' => 'update']);

            foreach ($results as $i => $result) {
                if (! ($result['success'] ?? false) && ($result['code'] ?? null) !== 404) {
                    $failed[$chunk[$i]->product_id] = true;
                    report(new RuntimeException('Rank sync: '.($result['error'] ?? 'unknown error')));
                }
            }
        }

        return ['failed' => $failed, 'offers' => $lines->count()];
    }

    /** Staff entries and new "was" prices reach the offers of those packs at other stores. */
    private function pushUsualPrices(Carbon $startedAt): void
    {
        $since = Cache::get(self::USUAL_PRICES_SYNCED);
        $variantIds = RegularPrice::query()
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
            ->distinct()
            ->pluck('product_variant_id');

        Offer::query()->live()
            ->whereIn('product_variant_id', $variantIds)
            ->with(OfferDocument::RELATIONS)
            ->chunkById(500, fn ($offers) => Offer::syncSearch($offers));

        Cache::forever(self::USUAL_PRICES_SYNCED, $startedAt->toIso8601String());
    }
}
