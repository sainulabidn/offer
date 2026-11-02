<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/** `path` is the upload until GenerateImageSizes replaces it with the 350 px square, "{base}-350.webp", beside "{base}-160.webp". */
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
            GenerateImageSizes::disk()->delete(GenerateImageSizes::allFiles($image->path));
        });
    }

    /** For the website: base URL (append "-160.webp" or "-350.webp"), alt text and the square's size. */
    public function toPublicArray(): array
    {
        return [
            'base' => GenerateImageSizes::baseUrl($this->path),
            'alt' => $this->getTranslation('alt', 'en', false) ?: null,
            'width' => $this->width,
            'height' => $this->height,
            'credit' => $this->credit,
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
