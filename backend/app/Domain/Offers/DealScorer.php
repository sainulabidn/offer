<?php

namespace App\Domain\Offers;

/**
 * How good is this deal, really? Compares the offer with the same variant's prices over the previous
 * 90 days (all stores), so a big "was" discount on an inflated "was" price doesn't fool anyone.
 *
 * - deal_score: % below the 90-day median price per unit (negative = more expensive than usual)
 * - is_lowest_90d: no store charged less for this pack in 90 days
 * - was_price_suspicious: the "was" price is over 10% above anything this chain charged in 90 days
 */
final class DealScorer
{
    public const int WINDOW_DAYS = 90;

    public function score(Offer $offer): void
    {
        $history = Offer::query()
            ->where('product_variant_id', $offer->product_variant_id)
            ->whereKeyNot($offer->id)
            ->whereIn('status', [OfferStatus::Published, OfferStatus::Expired])
            ->whereDate('valid_from', '>=', $offer->valid_from->copy()->subDays(self::WINDOW_DAYS))
            ->whereDate('valid_from', '<=', $offer->valid_from)
            ->get(['chain_id', 'price', 'regular_price', 'effective_unit_price']);

        $unitPrices = $history->pluck('effective_unit_price')->filter()->map(fn ($p) => (float) $p)->sort()->values();
        $current = $offer->effective_unit_price !== null ? (float) $offer->effective_unit_price : null;

        $score = null;
        $lowest = false;
        if ($current !== null && $unitPrices->isNotEmpty()) {
            $median = $unitPrices->median();
            $score = $median > 0 ? round(($median - $current) / $median * 100, 2) : null;
            $lowest = $current <= $unitPrices->first();
        }

        // Highest real price this chain charged for the pack recently, regular or promotional.
        $chainPrices = $history->where('chain_id', $offer->chain_id)
            ->flatMap(fn (Offer $o) => array_filter([(float) $o->price, $o->regular_price !== null ? (float) $o->regular_price : null]));
        $suspicious = $offer->regular_price !== null
            && $chainPrices->isNotEmpty()
            && (float) $offer->regular_price > $chainPrices->max() * 1.10;

        $offer->forceFill([
            'deal_score' => $score,
            'is_lowest_90d' => $lowest,
            'was_price_suspicious' => $suspicious,
        ]);
    }
}
