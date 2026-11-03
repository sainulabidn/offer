<?php

namespace App\Domain\Offers;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochurePage;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Engagement\Like;
use App\Domain\Engagement\Report;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Search\OfferDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;
use Spatie\Translatable\HasTranslations;

/** A price for a variant at a chain's branches for given dates, taken from a brochure. */
class Offer extends Model
{
    use Audited, RecordsCreator;
    use HasTranslations, Searchable;

    protected $guarded = ['validity'];

    public array $translatable = ['promo_label', 'condition_label'];

    protected function casts(): array
    {
        return [
            'bbox' => 'array',
            'price' => 'decimal:2',
            'regular_price' => 'decimal:2',
            'promo_type' => PromoType::class,
            'promo_params' => 'array',
            'conditions' => 'array',
            'unit_price' => 'decimal:4',
            'effective_unit_price' => 'decimal:4',
            'discount_pct' => 'decimal:2',
            'deal_score' => 'decimal:2',
            'is_lowest_90d' => 'boolean',
            'was_price_suspicious' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'status' => OfferStatus::class,
            'confidence' => 'decimal:3',
            'raw_text' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** Published offers valid on the given day (default: today in the market's timezone). */
    public function scopeLive(Builder $query, ?string $day = null): Builder
    {
        $day ??= now(config('app.market_timezone'))->toDateString();

        return $query->where('status', OfferStatus::Published)
            ->whereDate('valid_from', '<=', $day)
            ->whereDate('valid_to', '>=', $day);
    }

    /** Offers anyone can buy: leaves out prices only the store's own employees get. */
    public function scopeForShoppers(Builder $query): Builder
    {
        return $query->whereRaw("coalesce((conditions->>'staff_only')::boolean, false) = false");
    }

    public function isStaffOnly(): bool
    {
        return (bool) ($this->conditions['staff_only'] ?? false);
    }

    /**
     * Only offers valid today are searchable, by the market's calendar: a scheduled offer joins on its
     * first day (ExpireOffers), and expired ones and products not approved or active drop out of Typesense.
     */
    public function shouldBeSearchable(): bool
    {
        $today = now(config('app.market_timezone'))->toDateString();
        if ($this->status !== OfferStatus::Published || $this->valid_from?->toDateString() > $today || $this->valid_to?->toDateString() < $today) {
            return false;
        }
        $product = $this->loadMissing('variant.product')->variant?->product;

        return $product !== null && $product->isApproved() && $product->status === ProductStatus::Active;
    }

    /**
     * Sends these offers to Typesense and removes any that may no longer show. Collection::searchable()
     * skips shouldBeSearchable(), so bulk indexing goes through here.
     *
     * @param  Collection<int, Offer>  $offers
     */
    public static function syncSearch(Collection $offers): void
    {
        [$shown, $hidden] = $offers->partition->shouldBeSearchable();
        $shown->searchable();
        $hidden->unsearchable();
    }

    public function toSearchableArray(): array
    {
        return OfferDocument::from($this);
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with(OfferDocument::RELATIONS);
    }

    protected static function booted(): void
    {
        static::saving(function (Offer $offer) {
            // Chain and default dates come from the brochure; prices are derived, never typed.
            if ($offer->brochure_id && (! $offer->chain_id || ! $offer->valid_from || ! $offer->valid_to)) {
                $brochure = Brochure::findOrFail($offer->brochure_id);
                $offer->chain_id ??= $brochure->chain_id;
                $offer->valid_from ??= $brochure->valid_from;
                $offer->valid_to ??= $brochure->valid_to;
            }
            OfferPricer::apply($offer);
        });
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

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    /** Extra variants for "assorted" deals. */
    public function rangeVariants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'offer_variants');
    }

    /** When set, overrides the brochure's branch scope ("selected branches only"). */
    public function branchOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'offer_branch');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }
}
