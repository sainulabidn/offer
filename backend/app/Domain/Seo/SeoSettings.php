<?php

namespace App\Domain\Seo;

use App\Domain\Settings\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Site-wide search engine and social settings: the name in titles, the default share picture,
 * social profiles and the codes that prove site ownership to Google, Bing and others.
 * Stored as site settings under "seo.*" keys; the website gets them together with the SEO pages.
 */
final class SeoSettings
{
    public const array DEFAULTS = [
        'site_name' => 'offer',
        'tagline' => 'Supermarket deals near you',
        'description' => 'Compare supermarket offers across the UAE by true unit price.',
        // Shown on Facebook, WhatsApp, X and LinkedIn when a page has no picture of its own. 1200 × 630 suits them all.
        'share_image_path' => null,
        'x_handle' => null,
        'facebook_app_id' => null,
        // Official profiles, for the organisation in search results ("sameAs").
        'social_profiles' => [],
        'google_verification' => null,
        'bing_verification' => null,
        'yandex_verification' => null,
        'facebook_verification' => null,
        'pinterest_verification' => null,
    ];

    /** The website's copy; also cleared when an SEO page changes, because both go out in one response. */
    public const string CACHE_KEY = 'seo-website';

    private const string VALUES_KEY = 'seo-settings';

    private const string PREFIX = 'seo.';

    public static function all(): array
    {
        $saved = Cache::rememberForever(self::VALUES_KEY, fn () => SiteSetting::query()
            ->where('key', 'like', self::PREFIX.'%')
            ->pluck('value', 'key')
            ->mapWithKeys(fn ($value, string $key) => [substr($key, strlen(self::PREFIX)) => $value])
            ->all());

        return array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    /** Saves the given settings; a value equal to its default, or empty, is removed. */
    public static function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            if ($value === self::DEFAULTS[$key] || $value === '' || $value === []) {
                SiteSetting::query()->where('key', self::PREFIX.$key)->get()->each->delete();
            } else {
                SiteSetting::query()->updateOrCreate(['key' => self::PREFIX.$key], ['value' => $value]);
            }
        }
        Cache::forget(self::VALUES_KEY);
        Cache::forget(self::CACHE_KEY);
    }

    /** The settings and every active SEO page, as the website reads them. */
    public static function forWebsite(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $settings = self::all();
            $image = $settings['share_image_path'];
            unset($settings['share_image_path']);

            return [
                'settings' => [...$settings, 'share_image' => $image ? Storage::disk(config('filesystems.media_disk'))->url($image) : null],
                'pages' => SeoPage::query()->where('is_active', true)->orderBy('path')->get()->map->toPublicArray()->all(),
            ];
        });
    }
}
