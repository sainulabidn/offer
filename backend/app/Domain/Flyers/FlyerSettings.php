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

    /** Models offered in the picker, per provider; any other id works once it has a price row. */
    public const array MODELS = [
        'claude' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'],
        'openai' => ['gpt-6-astra', 'gpt-6.1-sol', 'gpt-6.1-terra', 'gpt-6-luna', 'gpt-5.4-mini', 'gpt-4.1-mini'],
    ];

    /** A typical flyer page: picture and page text, cached instructions, ~30 offers out. For the cost estimates. */
    private const array PAGE_TOKENS = ['input' => 3700, 'cache_read' => 2000, 'output' => 2300];

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
            // OpenAI (developers.openai.com/api/docs/pricing, Standard tier, prompts up to 272K): cached input is
            // billed at cache_read; there is no separate charge for writing the cache.
            'gpt-6-astra' => ['input' => 10, 'output' => 50, 'cache_write' => 10, 'cache_read' => 1],
            'gpt-6.1-sol' => ['input' => 2, 'output' => 10, 'cache_write' => 2, 'cache_read' => 0.1],
            'gpt-6.1-terra' => ['input' => 1, 'output' => 5, 'cache_write' => 1, 'cache_read' => 0.05],
            'gpt-6-luna' => ['input' => 0.1, 'output' => 0.5, 'cache_write' => 0.1, 'cache_read' => 0.01],
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.5, 'cache_write' => 0.75, 'cache_read' => 0.075],
            'gpt-4.1-mini' => ['input' => 0.4, 'output' => 1.6, 'cache_write' => 0.4, 'cache_read' => 0.1],
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

        $settings = array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        // Models added to the defaults later appear even after admins saved their own table; saved rows win.
        $settings['prices'] = array_replace(self::DEFAULTS['prices'], (array) $settings['prices']);

        return $settings;
    }

    /** Rough USD for one flyer page on this model, from its price row (null without one). */
    public static function pageCost(string $model, bool $batch = false): ?float
    {
        $price = self::all()['prices'][$model] ?? null;
        if (! is_array($price)) {
            return null;
        }
        $usd = (self::PAGE_TOKENS['input'] * (float) ($price['input'] ?? 0)
            + self::PAGE_TOKENS['cache_read'] * (float) ($price['cache_read'] ?? $price['input'] ?? 0)
            + self::PAGE_TOKENS['output'] * (float) ($price['output'] ?? 0)) / 1_000_000;

        return $batch ? $usd * (float) self::get('batch_discount') : $usd;
    }

    /** The provider a known model belongs to, or null for ids not in MODELS. */
    public static function providerOf(string $model): ?string
    {
        foreach (self::MODELS as $provider => $models) {
            if (in_array($model, $models, true)) {
                return $provider;
            }
        }

        return null;
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
