<?php

namespace App\Domain\Shopper;

use App\Domain\Settings\HomeSettings;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A signed-in shopper's activity, for those who agreed to keep it on their account: one fading score per
 * store or item, with no event log. new = old × 0.5^(days since ÷ half-life) + what just happened.
 * The browser keeps the same numbers (see @offer/rank profile.ts).
 */
final class Affinities
{
    public const array KINDS = ['chain', 'branch', 'product', 'group', 'category', 'brand'];

    public const int MAX_BATCH = 500;

    private const int TOP_PER_KIND = 100;

    /** @param  list<array{kind: string, id: int, score: float, at: string}>  $items */
    public function add(User $user, array $items): void
    {
        // One row per target: an upsert can't touch the same row twice in one statement.
        $rows = [];
        foreach ($items as $item) {
            $key = "{$item['kind']}:{$item['id']}";
            $at = Carbon::parse($item['at'])->min(now());
            $rows[$key] = [
                'user_id' => $user->id,
                'kind' => $item['kind'],
                'target_id' => $item['id'],
                'score' => ($rows[$key]['score'] ?? 0) + (float) $item['score'],
                'events' => ($rows[$key]['events'] ?? 0) + 1,
                'touched_at' => max($rows[$key]['touched_at'] ?? '', $at->toDateTimeString()),
            ];
        }
        if ($rows === []) {
            return;
        }

        $values = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?::timestamp)'));
        $bindings = array_merge(...array_map(array_values(...), array_values($rows)));

        DB::insert(<<<SQL
            INSERT INTO shopper_affinities (user_id, kind, target_id, score, events, touched_at) VALUES {$values}
            ON CONFLICT (user_id, kind, target_id) DO UPDATE SET
                score = shopper_affinities.score * POWER(0.5,
                    GREATEST(0, EXTRACT(EPOCH FROM excluded.touched_at - shopper_affinities.touched_at) / 86400)
                    / CASE WHEN shopper_affinities.kind IN ('chain', 'branch') THEN ?::numeric ELSE ?::numeric END
                ) + excluded.score,
                events = shopper_affinities.events + excluded.events,
                touched_at = GREATEST(shopper_affinities.touched_at, excluded.touched_at)
            SQL, [...$bindings, HomeSettings::get('store_half_life_days'), HomeSettings::get('item_half_life_days')]);
    }

    /** The strongest scores of each kind, for a browser that hasn't got them. */
    public function top(User $user): array
    {
        return DB::table(DB::raw('(SELECT *, ROW_NUMBER() OVER (PARTITION BY kind ORDER BY score DESC) AS n FROM shopper_affinities WHERE user_id = ?) ranked'))
            ->addBinding($user->id, 'from')
            ->where('n', '<=', self::TOP_PER_KIND)
            ->get(['kind', 'target_id', 'score', 'touched_at'])
            ->map(fn (object $row) => ['kind' => $row->kind, 'id' => (int) $row->target_id, 'score' => (float) $row->score, 'at' => Carbon::parse($row->touched_at)->toIso8601String()])
            ->all();
    }

    public function forget(User $user): void
    {
        ShopperAffinity::where('user_id', $user->id)->delete();
    }
}
