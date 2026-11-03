<?php

namespace App\Domain\Seo;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

/**
 * Search engine and social texts for one website path. A path under /deals/ is a landing page of its own
 * for the saved search filters ("/deals/basmati-rice-dubai"); any other path, such as "/dubai/c/dairy"
 * or "/products/almarai-milk", replaces what that page writes by itself. Empty fields keep the page's own.
 */
class SeoPage extends Model
{
    use Audited, RecordsCreator;
    use HasTranslations;

    /** Saved-filter landing pages live here; everything else is an override of an existing page. */
    public const string LANDING_PREFIX = '/deals/';

    /** Lower-case words and hyphens between slashes, no trailing slash: "/dubai/al-barsha/c/dairy". */
    public const string PATH_PATTERN = '#^/$|^(/[a-z0-9]+(-[a-z0-9]+)*)+$#';

    public const array SORTS = [
        'rank' => 'Most useful',
        'deal' => 'Biggest discount',
        'unit' => 'Cheapest per unit',
        'price' => 'Lowest price',
        'ending' => 'Ending soon',
        'liked' => 'Most liked',
    ];

    protected $guarded = [];

    public array $translatable = ['title', 'description', 'heading', 'intro'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'noindex' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SeoPage $page) {
            $page->path = self::cleanPath($page->path);
            // Filters only mean something on a landing page; elsewhere the page decides what it lists.
            $page->filters = $page->isLanding() ? self::cleanFilters($page->filters ?? []) : [];
        });
        static::saved(fn () => Cache::forget(SeoSettings::CACHE_KEY));
        static::deleted(fn () => Cache::forget(SeoSettings::CACHE_KEY));
    }

    /** "Dubai/C/Dairy/" → "/dubai/c/dairy"; a full URL keeps only its path. */
    public static function cleanPath(?string $path): string
    {
        $path = parse_url(trim((string) $path), PHP_URL_PATH) ?: '/';

        return '/'.trim(mb_strtolower($path), '/');
    }

    public function isLanding(): bool
    {
        return str_starts_with($this->path ?? '', self::LANDING_PREFIX);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk(config('filesystems.media_disk'))->url($this->image_path) : null;
    }

    /** Drops empty values, so "{}" means "every live offer". */
    private static function cleanFilters(array $filters): array
    {
        $ints = fn ($values) => array_values(array_unique(array_map('intval', array_filter((array) $values))));

        return array_filter([
            'q' => trim((string) ($filters['q'] ?? '')) ?: null,
            'category_id' => (int) ($filters['category_id'] ?? 0) ?: null,
            'product_group_id' => (int) ($filters['product_group_id'] ?? 0) ?: null,
            'brand_ids' => $ints($filters['brand_ids'] ?? []) ?: null,
            'chain_ids' => $ints($filters['chain_ids'] ?? []) ?: null,
            'region_id' => (int) ($filters['region_id'] ?? 0) ?: null,
            'min_discount' => (int) ($filters['min_discount'] ?? 0) ?: null,
            'card_only' => empty($filters['card_only']) ? null : true,
            'sort' => isset(self::SORTS[$filters['sort'] ?? '']) ? $filters['sort'] : null,
        ], fn ($value) => $value !== null);
    }

    /** What the website needs: English and Arabic texts, the share picture and, on a landing page, its filters. */
    public function toPublicArray(): array
    {
        $text = fn (string $field) => array_filter([
            'en' => $this->getTranslation($field, 'en', false) ?: null,
            'ar' => $this->getTranslation($field, 'ar', false) ?: null,
        ]) ?: null;

        return [
            'path' => $this->path,
            'title' => $text('title'),
            'description' => $text('description'),
            'heading' => $text('heading'),
            'intro' => $text('intro'),
            'image' => $this->imageUrl(),
            'filters' => $this->isLanding() ? (object) $this->filters : null,
            'noindex' => $this->noindex,
            'updated' => $this->updated_at?->toDateString(),
        ];
    }
}
