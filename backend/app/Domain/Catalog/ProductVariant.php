<?php

namespace App\Domain\Catalog;

use App\Domain\Offers\Offer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Translatable\HasTranslations;

/** One purchasable pack ("5 kg bag, India"). Brochure offers attach a price and dates to a variant. */
class ProductVariant extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name'];

    /** Mirrors the column default so net_base_qty can be computed before the first insert. */
    protected $attributes = ['pack_count' => 1];

    protected function casts(): array
    {
        return [
            'unit_qty' => 'decimal:3',
            'qty_max' => 'decimal:3',
            'net_base_qty' => 'decimal:3',
            'attributes' => 'array',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductVariant $variant) {
            $variant->net_base_qty = UnitPriceCalculator::netBaseQty(
                (int) $variant->pack_count,
                (string) $variant->unit_qty,
                Unit::findOrFail($variant->unit_code),
            );
        });
    }

    /** "India Gate Classic Basmati Rice · 5 kg" — needs product and optionValues loaded. */
    public function label(): string
    {
        $options = $this->optionValues->pluck('label')->implode(', ');

        return $this->product->name.' · '.($options !== '' ? $options : $this->slug);
    }

    /** Attach option values and store their sorted IDs, which must be unique per product. */
    public function syncOptionValues(array $optionValueIds): void
    {
        $ids = array_map('intval', $optionValueIds);
        sort($ids);

        DB::transaction(function () use ($ids) {
            $this->optionValues()->sync($ids);
            $this->forceFill(['option_signature' => implode('-', $ids)])->save();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_code');
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_variant_option_value');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }
}
