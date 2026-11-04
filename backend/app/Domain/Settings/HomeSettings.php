<?php

namespace App\Domain\Settings;

use Illuminate\Support\Facades\Cache;

/**
 * Home page and ranking settings: radius choices, how fast activity fades and how signals are weighed.
 * The website gets these with the store directory, so changing one needs no deploy.
 */
final class HomeSettings
{
    public const array DEFAULTS = [
        'radius_options' => [1, 2, 5, 10, 25, 50],
        'radius_default' => 10,
        'item_radius_default' => 50,
        'radius_max' => 100,
        // Usual prices older than this are hidden from shoppers.
        'usual_price_max_days' => 60,
        // A nearby store counts as walkable up to this straight-line distance.
        'walk_limit_m' => 600,
        'store_half_life_days' => 30,
        'item_half_life_days' => 21,
        'store_weights' => ['activity' => 0.5, 'near' => 0.3, 'grade' => 0.1, 'offers' => 0.1],
        'rank_weights' => ['grade' => 0.45, 'popularity' => 0.35, 'deal' => 0.2],
        'value_weights' => ['interest' => 0.4, 'grade' => 0.2, 'deal' => 0.2, 'popularity' => 0.1, 'near' => 0.1],
        'event_weights' => [
            'store_visit' => ['store' => 1, 'item' => 0],
            'click' => ['store' => 1, 'item' => 2],
            'directions' => ['store' => 5, 'item' => 0],
            'plan' => ['store' => 3, 'item' => 5],
            'like' => ['store' => 3, 'item' => 4],
            'not_interested' => ['store' => 0, 'item' => -8],
        ],
        'feed' => ['slots' => 12, 'max_per_group' => 2, 'max_per_chain' => 3, 'discovery_slots' => 2],
    ];

    private const string CACHE_KEY = 'home-settings';

    /** Every setting: saved values over the defaults. */
    public static function all(): array
    {
        $saved = Cache::rememberForever(self::CACHE_KEY, fn () => SiteSetting::query()->pluck('value', 'key')->all());

        return array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key];
    }

    /** Saves the given settings; a value equal to its default is removed, so later default changes apply. */
    public static function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            if ($value === self::DEFAULTS[$key]) {
                SiteSetting::query()->where('key', $key)->get()->each->delete();
            } else {
                SiteSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }
        Cache::forget(self::CACHE_KEY);
    }
}
