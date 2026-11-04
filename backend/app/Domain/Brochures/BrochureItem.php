<?php

namespace App\Domain\Brochures;

use App\Domain\Catalog\ProductVariant;
use App\Domain\Offers\Offer;
use App\Domain\Offers\PromoType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * One offer the AI read off a flyer page. It only becomes an Offer (a draft on the brochure) when someone
 * accepts it (Flyers\AcceptItems), so unchecked AI output never reaches the price wait or the website.
 */
class BrochureItem extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['promo_label', 'condition_label'];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'unit_qty' => 'decimal:3',
            'price' => 'decimal:2',
            'regular_price' => 'decimal:2',
            'promo_type' => PromoType::class,
            'promo_params' => 'array',
            'conditions' => 'array',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'bbox' => 'array',
            'confidence' => 'decimal:3',
            'match_score' => 'decimal:3',
            'candidates' => 'array',
            'status' => ItemStatus::class,
            'issues' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** Matched, nothing to check, and the AI was sure enough: safe for "accept all clean". */
    public function isReadyToAccept(float $minConfidence): bool
    {
        return $this->product_variant_id !== null
            && $this->issues === []
            && $this->price !== null
            && (float) $this->confidence >= $minConfidence;
    }

    public function brochure(): BelongsTo
    {
        return $this->belongsTo(Brochure::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(BrochurePage::class, 'brochure_page_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
