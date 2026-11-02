<?php

namespace App\Domain\Engagement;

use Illuminate\Support\Facades\DB;

/** Public daily counts of what shoppers look at, for "most viewed". Knows nothing about who looked. */
final class Counters
{
    /** cohort: 1 = personal order, 2 = the group kept on the shared order, to show whether personal ranking helps. */
    public const array KINDS = ['product', 'group', 'chain', 'branch', 'cohort'];

    /** Event type => column. */
    public const array TYPES = ['view' => 'views', 'click' => 'clicks', 'plan' => 'plans', 'directions' => 'directions'];

    /** Rows older than this are deleted; a little over a year allows comparing with last year's season. */
    public const int KEEP_DAYS = 400;

    /** @param  list<array{kind: string, id: int, type: string}>  $events */
    public function add(array $events, int $regionId = 0): void
    {
        $day = now(config('app.market_timezone'))->toDateString();

        // One row per target: an upsert can't touch the same row twice in one statement.
        $rows = [];
        foreach ($events as $event) {
            $key = "{$event['kind']}:{$event['id']}";
            $rows[$key] ??= ['day' => $day, 'kind' => $event['kind'], 'target_id' => $event['id'], 'region_id' => $regionId] + array_fill_keys(self::TYPES, 0);
            $rows[$key][self::TYPES[$event['type']]]++;
        }

        $increments = array_map(fn (string $column) => DB::raw("engagement_daily.{$column} + excluded.{$column}"), array_combine(self::TYPES, self::TYPES));
        DB::table('engagement_daily')->upsert(array_values($rows), ['day', 'kind', 'target_id', 'region_id'], $increments);
    }

    /** @return int rows removed */
    public function prune(): int
    {
        return DB::table('engagement_daily')->where('day', '<', now(config('app.market_timezone'))->subDays(self::KEEP_DAYS)->toDateString())->delete();
    }
}
