<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

/** `path` is the uploaded original on the public disk; AVIF/WebP sizes (160–1200 px) are generated next to it. */
class ProductImage extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['alt'];

    protected static function booted(): void
    {
        static::saved(function (ProductImage $image) {
            if ($image->wasChanged('path') || $image->wasRecentlyCreated) {
                GenerateImageSizes::dispatch($image->id);
            }
        });

        static::deleted(function (ProductImage $image) {
            $base = GenerateImageSizes::basePath($image->path);
            $sizes = collect(GenerateImageSizes::WIDTHS)->flatMap(fn (int $w) => ["{$base}-{$w}.avif", "{$base}-{$w}.webp"]);
            Storage::disk('public')->delete([$image->path, ...$sizes]);
        });
    }

    /** For the website: base URL (append "-{width}.avif|webp"), alt text and the original's dimensions. */
    public function toPublicArray(): array
    {
        return [
            'base' => GenerateImageSizes::baseUrl($this->path),
            'alt' => $this->getTranslation('alt', 'en', false) ?: null,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
