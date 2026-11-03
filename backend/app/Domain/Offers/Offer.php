<?php

namespace App\Domain\Offers;

use App\Domain\Access\Access;
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
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
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
            'price_set_at' => 'datetime',
        ];
    }

    /** Changing these sets the price again, which store users may only do every few hours (PriceWait). */
    public const array PRICE_FIELDS = ['product_variant_id', 'price', 'regular_price', 'promo_type', 'promo_params'];

    /** Added on the Offers page or by CSV, rather than from a brochure. */
    public function isDirect(): bool
    {
        return $this->brochure?->type === Brochure::TYPE_DIRECT;
    }

    /** Published offers valid on the given day (default: today in the market's timezone). */
    public function scopeLive(Builder $query, ?string $day = null): Builder
    {
        $day ??= now(config('app.market_timezone'))->toDateString();

        return $query->where('status', OfferStatus::Published)
            ->whereDate('valid_from', '<=', $day)
            ->whereDate('valid_to', '>=', $day);
    }

    /** Live offers that are on right now: whole-day ones, and timed ones inside today's window. */
    public function scopeOnNow(Builder $query): Builder
    {
        $time = now(config('app.market_timezone'))->format('H:i:s');

        return $query->live()->where(fn (Builder $q) => $q
            ->whereNull('start_time')
            ->orWhere(fn (Builder $q) => $q->where('start_time', '<=', $time)->where('end_time', '>', $time)));
    }

    /** Only on during a daily window, e.g. 08:00–11:00 on each of its days. */
    public function isTimed(): bool
    {
        return $this->start_time !== null && $this->end_time !== null;
    }

    /** Valid today and, for a timed offer, inside today's window (market time). */
    public function isOnNow(): bool
    {
        $now = now(config('app.market_timezone'));
        $today = $now->toDateString();
        if ($this->valid_from?->toDateString() > $today || $this->valid_to?->toDateString() < $today) {
            return false;
        }

        return ! $this->isTimed() || (self::clock($this->start_time) <= $now->format('H:i:s') && $now->format('H:i:s') < self::clock($this->end_time));
    }

    /** When today's window closes, for the countdown; null for whole-day offers. */
    public function windowEndsAt(): ?CarbonInterface
    {
        return $this->isTimed() ? now(config('app.market_timezone'))->setTimeFromTimeString(self::clock($this->end_time)) : null;
    }

    /** "8:00 AM – 11:00 AM", or null for whole-day offers. */
    public function windowLabel(): ?string
    {
        if (! $this->isTimed()) {
            return null;
        }
        $format = fn (string $time) => Carbon::createFromTimeString(self::clock($time))->format('g:i A');

        return $format($this->start_time).' – '.$format($this->end_time);
    }

    /** "08:00" or "08:00:00" from a form or the database → "08:00:00". */
    private static function clock(string $time): string
    {
        return strlen($time) === 5 ? "{$time}:00" : $time;
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
     * Only offers on right now are searchable, by the market's clock: a scheduled offer joins on its first
     * day (ExpireOffers), a timed one only inside its daily window (SyncTimedOffers), and expired ones and
     * products not approved or active drop out of Typesense.
     */
    public function shouldBeSearchable(): bool
    {
        if ($this->status !== OfferStatus::Published || ! $this->isOnNow()) {
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
            if (! $offer->exists || $offer->isDirty(self::PRICE_FIELDS)) {
                // A backstop for every admin form: the forms and the CSV import check first, with a nicer message.
                PriceWait::check(Access::user(), $offer->chain_id, $offer->product_variant_id);
                $offer->price_set_at = now();
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
