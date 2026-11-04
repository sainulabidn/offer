<?php

namespace App\Domain\Flyers;

use App\Domain\Access\ReviewStatus;
use App\Domain\Catalog\PackSize;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Finds the catalog pack for a flyer item, without AI: a printed barcode, then what reviewers taught us
 * (FlyerAlias), then the product name by trigram similarity (pg_trgm), lifted when the brand matches and
 * decided by the pack size. Only packs shoppers can see are considered (approved, active, not merged).
 *
 * A pack is chosen on its own only with an exact size and a clear lead over the next one; otherwise the
 * best few are offered to the reviewer as candidates.
 */
final class VariantMatcher
{
    private const float BRAND_BONUS = 0.15;

    private const float SIZE_BONUS = 0.25;

    private const float SIZE_PENALTY = 0.3;

    /**
     * @param  array{name: string, brand_text: ?string, size_text: ?string}  $item  ItemNormalizer output
     * @return array{product_variant_id: ?int, match_method: ?string, match_score: ?float, candidates: list<array{id: int, score: float}>}
     */
    public function match(array $item, int $chainId, ?string $barcode = null): array
    {
        $size = PackSize::parse($item['size_text'] ?? null);

        if ($barcode && ($variant = $this->byBarcode($barcode))) {
            return $this->found($variant->id, 'barcode', 1.0);
        }
        $alias = FlyerAlias::find($chainId, $item['name'], $size?->key() ?? '');
        if ($alias && $this->usable()->whereKey($alias->product_variant_id)->exists()) {
            $alias->increment('hits');

            return $this->found($alias->product_variant_id, 'alias', 1.0);
        }

        $scored = $this->byName($item['name'], $item['brand_text'] ?? null, $size);
        $best = $scored[0] ?? null;
        $second = $scored[1] ?? null;
        $settings = FlyerSettings::all();
        $candidates = array_map(fn (array $row) => ['id' => $row['id'], 'score' => round($row['score'], 3)], array_slice($scored, 0, 3));

        $sure = $best
            && $best['size_exact']
            && $best['score'] >= (float) $settings['match_threshold']
            && ($second === null || (float) $settings['match_margin'] <= $best['score'] - $second['score']);

        return [
            'product_variant_id' => $sure ? $best['id'] : null,
            'match_method' => $sure ? 'name' : null,
            'match_score' => $best ? round(min(1, $best['score']), 3) : null,
            'candidates' => $candidates,
        ];
    }

    /**
     * Packs scored for this name, best first.
     *
     * @return list<array{id: int, score: float, size_exact: bool}>
     */
    private function byName(string $name, ?string $brand, ?PackSize $size): array
    {
        $needle = mb_strtolower($name);
        $rows = DB::select(<<<'SQL'
            SELECT v.id, v.net_base_qty, u.dimension, p.is_variable_weight,
                   greatest(similarity(lower(p.name->>'en'), ?), similarity(coalesce(p.name->>'ar', ''), ?)) AS name_score,
                   coalesce(similarity(lower(b.name->>'en'), ?), 0) AS brand_score
            FROM products p
            JOIN product_variants v ON v.product_id = p.id AND v.merged_into_id IS NULL
            JOIN units u ON u.code = v.unit_code
            LEFT JOIN brands b ON b.id = p.brand_id
            WHERE p.status = ? AND p.review_status = ?
              AND (lower(p.name->>'en') % ? OR (p.name->>'ar') % ?)
            ORDER BY name_score DESC
            LIMIT 40
            SQL, [$needle, $name, mb_strtolower((string) $brand), ProductStatus::Active->value, ReviewStatus::Approved->value, $needle, $name]);

        $net = $size && ! $size->byWeight ? $size->netBaseQty() : null;
        $scored = array_map(function (object $row) use ($brand, $size, $net) {
            $score = (float) $row->name_score;
            if (filled($brand) && (float) $row->brand_score >= 0.5) {
                $score += self::BRAND_BONUS;
            }
            $exact = false;
            if ($size) {
                $sameDimension = $row->dimension === $size->unit->dimension->value;
                $exact = $size->byWeight
                    ? $sameDimension && (bool) $row->is_variable_weight
                    : $sameDimension && $net !== null && abs((float) $row->net_base_qty - (float) $net) <= 0.01 * max(1, (float) $net);
                $score += $exact ? self::SIZE_BONUS : -self::SIZE_PENALTY;
            }

            return ['id' => (int) $row->id, 'score' => $score, 'size_exact' => $exact];
        }, $rows);

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    private function byBarcode(string $barcode): ?ProductVariant
    {
        $digits = preg_replace('/\D/', '', $barcode);
        if (strlen($digits) < 8 || strlen($digits) > 14) {
            return null;
        }

        return $this->usable()->whereIn('gtin', array_unique([$digits, str_pad($digits, 14, '0', STR_PAD_LEFT)]))->first();
    }

    /** Packs shoppers can see. */
    private function usable()
    {
        return ProductVariant::query()
            ->whereNull('merged_into_id')
            ->whereHas('product', fn ($q) => $q->where('status', ProductStatus::Active)->where('review_status', ReviewStatus::Approved));
    }

    private function found(int $variantId, string $method, float $score): array
    {
        return ['product_variant_id' => $variantId, 'match_method' => $method, 'match_score' => $score, 'candidates' => [['id' => $variantId, 'score' => $score]]];
    }
}
