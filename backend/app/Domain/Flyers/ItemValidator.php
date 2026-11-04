<?php

namespace App\Domain\Flyers;

use App\Domain\Offers\RegularPrice;

/**
 * What a reviewer should look at before accepting a flyer item. Nothing here blocks: an item with issues
 * waits for a person ("Needs a look") instead of being accepted with the clean ones.
 */
final class ItemValidator
{
    /**
     * @param  array<string, mixed>  $item  ItemNormalizer output plus the match
     * @return list<array{code: string, message: string}>
     */
    public static function issues(array $item, int $chainId, ?string $pageText): array
    {
        $issues = [];
        $add = function (string $code, string $message) use (&$issues) {
            $issues[] = ['code' => $code, 'message' => $message];
        };
        $price = (float) $item['price'];
        $was = $item['regular_price'] !== null ? (float) $item['regular_price'] : null;

        if ($item['product_variant_id'] === null) {
            $add('product', 'Pick the product: no pack matched for sure.');
        }
        if ($price > 99999) {
            $add('price', 'The price looks wrong.');
        }
        if ($was !== null && $was <= $price) {
            $add('was', 'The "was" price is not above the offer price.');
        } elseif ($was !== null && $price / $was < 0.1) {
            $add('discount', 'More than 90% off: check both prices.');
        }
        // Text PDFs carry exact prices; a price the PDF's text doesn't contain was probably misread.
        if (filled($pageText) && ! self::priceInText($price, (string) $pageText) && ! self::priceInText((float) ($item['promo_params']['total'] ?? 0), (string) $pageText)) {
            $add('price_text', 'This price is not in the PDF\'s own text: check it on the page.');
        }
        $today = now(config('app.market_timezone'))->toDateString();
        if ($item['valid_to'] !== null && ($item['valid_to'] < $today || ($item['valid_from'] !== null && $item['valid_from'] > $item['valid_to']))) {
            $add('dates', 'The dates read off the page are past or reversed.');
        }
        if ($item['product_variant_id'] !== null && ($usual = self::usualPrice($item['product_variant_id'], $chainId))) {
            if ($price > $usual * 2.5 || $price < $usual * 0.2) {
                $add('usual', 'Far from this store\'s usual price (AED '.number_format($usual, 2).'): check the product and pack size.');
            }
        }
        if ($item['confidence'] !== null && (float) $item['confidence'] < (float) FlyerSettings::get('auto_min_confidence')) {
            $add('unsure', 'The AI was unsure about this one.');
        }

        return $issues;
    }

    /** 12.95 appears as "12.95", "12,95" or "12 .95" / "12 95" (big digits, small decimals). */
    private static function priceInText(float $price, string $text): bool
    {
        if ($price <= 0) {
            return false;
        }
        [$whole, $cents] = explode('.', number_format($price, 2, '.', ''));
        $pattern = $cents === '00'
            ? "/(?<![\\d.,]){$whole}(?:[.,]00|(?![\\d.,]\\d))/"
            : "/(?<![\\d.,]){$whole}\\s*[.,]?\\s*{$cents}(?!\\d)/";

        return (bool) preg_match($pattern, $text);
    }

    private static function usualPrice(int $variantId, int $chainId): ?float
    {
        $price = RegularPrice::query()
            ->where('product_variant_id', $variantId)
            ->where('chain_id', $chainId)
            ->latest('observed_at')
            ->value('price');

        return $price !== null ? (float) $price : null;
    }
}
