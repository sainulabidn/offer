<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Offers\Offer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Product page data: details, variants, and every store's live price per variant (cheapest per unit first). */
class ProductController extends Controller
{
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
                    'promo_type' => $offer->promo_type->value,
                    'promo_label' => $offer->getTranslation('promo_label', 'en', false) ?: null,
                    'valid_to' => $offer->valid_to->toDateString(),
                ]),
            ]),
        ])->setCache(['public' => true, 'max_age' => 300, 's_maxage' => 300, 'stale_while_revalidate' => 600]);
    }
}
