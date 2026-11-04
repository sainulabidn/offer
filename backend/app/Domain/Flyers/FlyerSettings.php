<?php

namespace App\Domain\Flyers;

use App\Domain\Settings\SiteSetting;
use Illuminate\Support\Facades\Cache;

/**
 * What admins choose for the flyer AI: provider and model, publishing, budget and quotas, matching, and the
 * price table the AI bill is worked out with. Stored as site settings under "flyers.*" keys. API keys are
 * not here (site settings are in the activity log): they come from config/services.php.
 */
final class FlyerSettings
{
    public const array PROVIDERS = ['claude' => 'Claude (Anthropic)', 'openai' => 'OpenAI'];

    public const array DEFAULTS = [
        'provider' => 'claude',
        'model' => 'claude-opus-5-5',
        // low | medium | high: how hard the model thinks. Output, thinking included, is most of the bill.
        'effort' => 'low',
        // Tried when the main model fails or declines a page; empty = none.
        'fallback_provider' => null,
        'fallback_model' => null,
        // Flyers fetched from store websites go through the half-price batch API (answers within hours).
        'batch_for_sources' => true,
        // review: people check and publish. auto: a clean flyer publishes itself (see FinishFlyer).
        'publish_mode' => 'review',
        'auto_min_confidence' => 0.85,
        // Uploads made while this is spent wait on hold until it is raised or the month turns.
        'monthly_budget_usd' => 100,
        // Pages each person may upload a day; super admins have no limit.
        'user_daily_pages' => 200,
        'max_pages' => 60,
        // Long edge of the page picture sent to the AI. Never above the flyer's own resolution; Claude
        // shrinks anything over about 2,300 px (on an A4 page) itself, so more only costs more.
        'render_long_edge' => 2000,
        // Read every page as two overlapping halves (double the input, for very dense small print).
        'tile_dense_pages' => false,
        'arabic_names' => false,
        // Text PDFs only: skip pages whose text has no prices (covers, terms). Picture pages can't be checked.
        'skip_no_price_pages' => true,
        // Product matching by name: lowest score to suggest a pack, and the lead over the next one to pick it alone.
        'match_threshold' => 0.45,
        'match_margin' => 0.1,
        // USD per million tokens. Batch requests cost batch_discount × these.
        'prices' => [
            'claude-opus-5-5' => ['input' => 4, 'output' => 20, 'cache_write' => 5, 'cache_read' => 0.2],
            'claude-sonnet-5-5' => ['input' => 2, 'output' => 10, 'cache_write' => 2.5, 'cache_read' => 0.1],
            // Prompts up to 100,000 tokens; one flyer page is far below that.
            'claude-haiku-5-5' => ['input' => 0.1, 'output' => 0.5, 'cache_write' => 0.125, 'cache_read' => 0.01],
        ],
        'batch_discount' => 0.5,
    ];

    private const string CACHE_KEY = 'flyer-settings';

    private const string PREFIX = 'flyers.';

    public static function all(): array
    {
        $saved = Cache::rememberForever(self::CACHE_KEY, fn () => SiteSetting::query()
            ->where('key', 'like', self::PREFIX.'%')
            ->pluck('value', 'key')
            ->mapWithKeys(fn ($value, string $key) => [substr($key, strlen(self::PREFIX)) => $value])
            ->all());

        return array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key];
    }

    /** Saves the given settings; a value equal to its default, or empty, is removed. */
    public static function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            if ($value === self::DEFAULTS[$key] || $value === '' || $value === null) {
                SiteSetting::query()->where('key', self::PREFIX.$key)->get()->each->delete();
            } else {
                SiteSetting::query()->updateOrCreate(['key' => self::PREFIX.$key], ['value' => $value]);
            }
        }
        Cache::forget(self::CACHE_KEY);
    }

    /** Is the provider's API key set on this server? */
    public static function hasKey(string $provider): bool
    {
        return filled(config($provider === 'openai' ? 'services.openai.key' : 'services.anthropic.key'));
    }
}
