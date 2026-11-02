<?php

namespace App\Domain\Offers;

use App\Domain\Settings\HomeSettings;
use Illuminate\Support\Facades\DB;

/** Shelf prices entered by staff, and how far they are from giving every daily item its comparison prices. */
final class ShelfPrices
{
    /** Stores this big or bigger appear as comparison prices on product cards. */
    public const int COMPARED_GRADE = 2;

    /** A card's own store plus two others. */
    private const int STORES_FOR_FULL_CARD = 3;

    private const int DAILY_ESSENTIAL = 1;

    public function record(int $variantId, int $chainId, string $price, ?string $day = null): RegularPrice
    {
        return RegularPrice::updateOrCreate(
            ['product_variant_id' => $variantId, 'chain_id' => $chainId, 'observed_at' => $day ?? now(config('app.market_timezone'))->toDateString()],
            ['price' => $price, 'source' => RegularPrice::SOURCE_STAFF],
        );
    }

    /**
     * Daily items on offer now: how many have prices from enough big stores for a full card, and how
     * many have no photo.
     *
     * @return array{items: int, covered: int, no_photo: int}
     */
    public function coverage(): array
    {
        $today = now(config('app.market_timezone'))->toDateString();
        $oldest = now(config('app.market_timezone'))->subDays(HomeSettings::get('usual_price_max_days'))->toDateString();

        $row = DB::selectOne(<<<'SQL'
            WITH live AS (
                SELECT product_variant_id, chain_id FROM offers
                WHERE status = 'published' AND valid_from <= ?::date AND valid_to >= ?::date
            ), daily AS (
                SELECT DISTINCT v.id AS variant_id, p.id AS product_id
                FROM live
                JOIN product_variants v ON v.id = live.product_variant_id
                JOIN products p ON p.id = v.product_id
                JOIN product_groups g ON g.id = p.product_group_id
                WHERE COALESCE(p.grade, g.grade, 3) = ?
            ), priced AS (
                SELECT product_variant_id, chain_id FROM live
                UNION
                SELECT product_variant_id, chain_id FROM regular_prices WHERE observed_at >= ?::date
            )
            SELECT COUNT(*) AS items,
                   COUNT(*) FILTER (WHERE (
                       SELECT COUNT(*) FROM priced
                       JOIN chains c ON c.id = priced.chain_id
                       WHERE priced.product_variant_id = d.variant_id AND c.is_active AND c.grade <= ?
                   ) >= ?) AS covered,
                   COUNT(*) FILTER (WHERE NOT EXISTS (
                       SELECT 1 FROM product_images i WHERE i.product_id = d.product_id AND i.width IS NOT NULL
                   )) AS no_photo
            FROM daily d
            SQL, [$today, $today, self::DAILY_ESSENTIAL, $oldest, self::COMPARED_GRADE, self::STORES_FOR_FULL_CARD]);

        return ['items' => (int) $row->items, 'covered' => (int) $row->covered, 'no_photo' => (int) $row->no_photo];
    }
}
