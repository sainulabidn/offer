<?php

namespace App\Domain\Flyers;

use App\Domain\Catalog\PackSize;
use App\Domain\Flyers\Ai\PageResult;
use App\Domain\Offers\PromoType;

/**
 * Turns one AI item (FlyerSchema) into brochure_items columns, with the same promotion rules as the CSV
 * import: multi-buy is {qty, total} with the price of one, buy-n-get-m is {buy, get}, a loyalty-card price
 * sets conditions.card_required and a printed limit conditions.max_per_customer.
 */
final class ItemNormalizer
{
    /** @return array<string, mixed> */
    public static function normalize(array $item, PageResult $page): array
    {
        $price = round((float) $item['price'], 2);
        $was = is_numeric($item['was'] ?? null) && (float) $item['was'] > 0 ? round((float) $item['was'], 2) : null;
        $type = PromoType::tryFrom((string) ($item['promo'] ?? '')) ?? PromoType::Price;
        $qty = is_numeric($item['qty'] ?? null) ? (int) $item['qty'] : null;
        $free = is_numeric($item['free'] ?? null) ? (int) $item['free'] : null;
        $params = [];

        if ($type === PromoType::Multibuy && $qty >= 2) {
            // "2 for 10": the deal is {qty, total}; the price of one is what each costs in the deal.
            $params = ['qty' => $qty, 'total' => number_format($price, 2, '.', '')];
            $price = round($price / $qty, 2);
        } elseif ($type === PromoType::Bogo && $qty >= 1 && $free >= 1) {
            $params = ['buy' => $qty, 'get' => $free];
        } elseif (in_array($type, [PromoType::Multibuy, PromoType::Bogo], true)) {
            $type = $was ? PromoType::WasNow : PromoType::Price; // the deal's numbers weren't readable
        }
        if ($type === PromoType::Price && $was !== null) {
            $type = PromoType::WasNow;
        }
        $card = ($item['card'] ?? false) === true;
        if ($card && $type === PromoType::Price) {
            $type = PromoType::CardPrice;
        }

        $note = trim((string) ($item['note'] ?? '')) ?: null;
        $isLimit = $note !== null && preg_match('/\b(max|limit|per customer|maximum|min)\b/i', $note);
        if ($type === PromoType::PercentOff && is_numeric($item['pct'] ?? null) && $note === null) {
            $note = rtrim(rtrim(number_format((float) $item['pct'], 1, '.', ''), '0'), '.').'% off';
        }
        $size = PackSize::parse($item['size'] ?? null);

        return [
            'raw' => $item,
            'name' => mb_substr($item['name'], 0, 255),
            'name_ar' => filled($item['ar'] ?? null) ? mb_substr((string) $item['ar'], 0, 255) : null,
            'brand_text' => filled($item['brand'] ?? null) ? mb_substr((string) $item['brand'], 0, 120) : null,
            'size_text' => filled($item['size'] ?? null) ? mb_substr((string) $item['size'], 0, 80) : null,
            'pack_count' => $size?->packCount,
            'unit_qty' => $size?->unitQty,
            'unit_code' => $size?->unit->code,
            'price' => number_format($price, 2, '.', ''),
            'regular_price' => $was !== null ? number_format($was, 2, '.', '') : null,
            'promo_type' => $type,
            'promo_params' => $params,
            'promo_label' => $note && ! $isLimit && $type !== PromoType::Price ? ['en' => mb_substr($note, 0, 120)] : null,
            'condition_label' => $note && $isLimit ? ['en' => mb_substr($note, 0, 120)] : null,
            'conditions' => array_filter([
                'max_per_customer' => is_numeric($item['limit'] ?? null) && (int) $item['limit'] > 0 ? (int) $item['limit'] : null,
                'card_required' => $card ?: null,
            ]),
            'valid_from' => $page->validFrom,
            'valid_to' => $page->validTo,
            'bbox' => self::box($item['box'] ?? null),
            'confidence' => is_numeric($item['sure'] ?? null) ? max(0, min(1, round((float) $item['sure'], 3))) : null,
        ];
    }

    /** [left, top, right, bottom] in thousandths → {x, y, w, h} in 0–1, as offers.bbox; null when unusable. */
    public static function box(mixed $box): ?array
    {
        if (! is_array($box) || count($box) !== 4 || array_filter($box, fn ($v) => ! is_numeric($v))) {
            return null;
        }
        [$left, $top, $right, $bottom] = array_map(fn ($v) => max(0, min(1000, (float) $v)) / 1000, array_values($box));
        if ($right <= $left || $bottom <= $top) {
            return null;
        }

        return ['x' => round($left, 4), 'y' => round($top, 4), 'w' => round($right - $left, 4), 'h' => round($bottom - $top, 4)];
    }
}
