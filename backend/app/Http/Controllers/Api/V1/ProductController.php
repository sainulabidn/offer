<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Product page data: details, variants, and every store's live price per variant (cheapest per unit first). */
class ProductController extends Controller
{
    /** Price history for the chart: per variant and store, every published offer of the last 120 days. */
    public function history(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)->where('status', ProductStatus::Active)->firstOrFail();

        $offers = Offer::query()
            ->whereHas('variant', fn ($q) => $q->where('product_id', $product->id))
            ->whereIn('status', [OfferStatus::Published, OfferStatus::Expired])
            ->whereDate('valid_from', '>=', now(config('app.market_timezone'))->subDays(120))
            ->with('chain')
            ->orderBy('valid_from')
            ->get();

        return response()->json($offers->groupBy('product_variant_id')->map(fn ($variantOffers) => $variantOffers
            ->groupBy('chain_id')
            ->map(fn ($chainOffers) => [
                'chain' => $chainOffers->first()->chain->name,
                'points' => $chainOffers->map(fn (Offer $o) => [
                    'from' => $o->valid_from->toDateString(),
                    'to' => $o->valid_to->toDateString(),
                    'price' => (float) $o->price,
                    'regular_price' => $o->regular_price !== null ? (float) $o->regular_price : null,
                ])->values(),
            ])->values()))
            ->setCache(['public' => true, 'max_age' => 600, 's_maxage' => 3600]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::Active)
            ->with([
                'brand', 'category', 'productGroup', 'images',
                'variants' => fn ($q) => $q->whereNull('merged_into_id')->orderBy('sort'),
                'variants.optionValues',
                'variants.offers' => fn ($q) => $q->live()->with('chain')->orderBy('effective_unit_price'),
            ])
            ->firstOrFail();

        $group = $product->productGroup;
        $quantity = rtrim(rtrim((string) $group->comparison_quantity, '0'), '.');
        $unitLabel = 'per '.($quantity === '1' ? '' : $quantity.' ').$group->comparison_unit_code;

        return response()->json([
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->getTranslation('name', 'en'),
            'name_ar' => $product->getTranslation('name', 'ar', false) ?: null,
            'description' => $product->getTranslation('description', 'en', false) ?: null,
            'brand' => $product->brand ? ['name' => $product->brand->name, 'slug' => $product->brand->slug] : null,
            'category' => ['id' => $product->category->id, 'name' => $product->category->name, 'slug' => $product->category->slug],
            'product_group' => ['id' => $group->id, 'name' => $group->name, 'slug' => $group->slug, 'unit_label' => $unitLabel],
            'attributes' => $product->attributes ?: (object) [],
            'like_count' => $product->like_count,
            'images' => $product->images->whereNotNull('width')->values()->map->toPublicArray(),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'slug' => $variant->slug,
                'label' => $variant->optionValues->pluck('label')->implode(', ') ?: $variant->slug,
                'is_default' => $variant->is_default,
                'offers' => $variant->offers->map(fn (Offer $offer) => [
                    'id' => $offer->id,
                    'chain' => ['id' => $offer->chain->id, 'name' => $offer->chain->name, 'slug' => $offer->chain->slug],
                    'price' => (float) $offer->price,
                    'regular_price' => $offer->regular_price !== null ? (float) $offer->regular_price : null,
                    'effective_unit_price' => $offer->effective_unit_price !== null ? (float) $offer->effective_unit_price : null,
                    'unit_label' => $unitLabel,
                    'discount_pct' => $offer->discount_pct !== null ? (float) $offer->discount_pct : null,
                    'deal_score' => $offer->deal_score !== null ? (float) $offer->deal_score : null,
                    'is_lowest_90d' => $offer->is_lowest_90d,
                    'was_price_suspicious' => $offer->was_price_suspicious,
                    'promo_type' => $offer->promo_type->value,
                    'promo_label' => $offer->getTranslation('promo_label', 'en', false) ?: null,
                    'valid_to' => $offer->valid_to->toDateString(),
                ]),
            ]),
        ])->setCache(['public' => true, 'max_age' => 300, 's_maxage' => 300, 'stale_while_revalidate' => 600]);
    }
}
