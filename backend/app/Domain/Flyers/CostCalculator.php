<?php

namespace App\Domain\Flyers;

use App\Domain\Flyers\Ai\TokenUsage;

/**
 * USD for an AI call, from the admins' price table (FlyerSettings "prices", per million tokens). A model
 * without a price row isn't run at all, so the monthly budget can always be enforced.
 */
final class CostCalculator
{
    public static function hasPrice(string $model): bool
    {
        return self::price($model) !== null;
    }

    public static function cost(string $model, TokenUsage $usage, bool $batch = false): ?float
    {
        $price = self::price($model);
        if ($price === null) {
            return null;
        }

        $usd = ($usage->input * (float) ($price['input'] ?? 0)
            + $usage->cacheWrite * (float) ($price['cache_write'] ?? $price['input'] ?? 0)
            + $usage->cacheRead * (float) ($price['cache_read'] ?? $price['input'] ?? 0)
            + $usage->output * (float) ($price['output'] ?? 0)) / 1_000_000;

        return round($batch ? $usd * (float) FlyerSettings::get('batch_discount') : $usd, 5);
    }

    /** @return array{input?: float, output?: float, cache_write?: float, cache_read?: float}|null */
    private static function price(string $model): ?array
    {
        $prices = (array) FlyerSettings::get('prices');

        return isset($prices[$model]) && is_array($prices[$model]) ? $prices[$model] : null;
    }
}
