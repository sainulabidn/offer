<?php

namespace App\Domain\Offers;

use App\Domain\Catalog\ProductVariant;
use App\Domain\Catalog\UnitPriceCalculator;

/**
 * Fills an offer's derived prices before it is saved:
 * effective price per item (multi-buy, buy-n-get-m), unit prices for comparison, and discount %.
 */
final class OfferPricer
{
    /** Price of one item when the shopper takes the promotion: "2 for 10" → 5.00, "buy 2 get 1" at 9 → 6.00. */
    public static function effectivePrice(string $price, PromoType $type, array $params): string
    {
        return match ($type) {
            PromoType::Multibuy => isset($params['qty'], $params['total']) && (int) $params['qty'] > 0
                ? bcdiv((string) $params['total'], (string) (int) $params['qty'], 4)
                : $price,
            PromoType::Bogo => isset($params['buy'], $params['get']) && (int) $params['buy'] > 0
                ? bcdiv(bcmul($price, (string) (int) $params['buy'], 4), (string) ((int) $params['buy'] + (int) $params['get']), 4)
                : $price,
            default => $price,
        };
    }

    public static function apply(Offer $offer): void
    {
        $variant = ProductVariant::with(['unit', 'product.productGroup.comparisonUnit'])->findOrFail($offer->product_variant_id);
        $group = $variant->product->productGroup;

        $price = (string) $offer->price;
        $effective = self::effectivePrice($price, $offer->promo_type ?? PromoType::Price, $offer->promo_params ?? []);
        $perUnit = fn (string $amount) => UnitPriceCalculator::perComparisonUnit(
            $amount,
            (string) $variant->net_base_qty,
            $variant->unit,
            (string) $group->comparison_quantity,
            $group->comparisonUnit,
        );

        $offer->unit_price = $perUnit($price);
        $offer->effective_unit_price = $perUnit($effective);

        $regular = $offer->regular_price !== null ? (string) $offer->regular_price : null;
        $offer->discount_pct = $regular !== null && bccomp($regular, '0', 2) > 0 && bccomp($effective, $regular, 4) < 0
            // bcmath truncates, so add half a cent's worth before cutting to 2 decimals.
            ? bcadd(bcmul(bcsub('1', bcdiv($effective, $regular, 8), 8), '100', 6), '0.005', 2)
            : null;
    }
}
