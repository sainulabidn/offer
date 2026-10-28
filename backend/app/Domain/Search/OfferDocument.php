<?php

namespace App\Domain\Search;

use App\Domain\Catalog\GenerateImageSizes;
use App\Domain\Offers\Offer;
use App\Domain\Offers\PromoType;

/**
 * The Typesense document for one published offer. It carries everything an offer card needs,
 * so the website never queries PostgreSQL. Optional fields are left out when empty.
 */
final class OfferDocument
{
    /** Relations needed to build documents; eager-load these when indexing many offers. */
    public const array RELATIONS = [
        'chain',
        'variant.unit',
        'variant.optionValues',
        'variant.product.brand',
        'variant.product.category',
        'variant.product.productGroup',
        'variant.product.images',
    ];

    public static function schema(): array
    {
        return [
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'product_id', 'type' => 'int32'],
                ['name' => 'product_slug', 'type' => 'string', 'index' => false, 'optional' => true],
                ['name' => 'product_name', 'type' => 'string'],
                ['name' => 'product_name_ar', 'type' => 'string', 'optional' => true],
                ['name' => 'variant_id', 'type' => 'int32'],
                ['name' => 'variant_slug', 'type' => 'string', 'index' => false, 'optional' => true],
                ['name' => 'variant_label', 'type' => 'string'],
                ['name' => 'brand', 'type' => 'string', 'facet' => true, 'optional' => true],
                ['name' => 'product_group', 'type' => 'string', 'facet' => true],
                ['name' => 'product_group_id', 'type' => 'int32', 'facet' => true],
                ['name' => 'category_ids', 'type' => 'int32[]', 'facet' => true],
                ['name' => 'chain_id', 'type' => 'int32', 'facet' => true],
                ['name' => 'chain_name', 'type' => 'string', 'facet' => true],
                ['name' => 'chain_slug', 'type' => 'string', 'index' => false, 'optional' => true],
                ['name' => 'brochure_id', 'type' => 'int32', 'facet' => true],
                ['name' => 'price', 'type' => 'float'],
                ['name' => 'regular_price', 'type' => 'float', 'optional' => true],
                ['name' => 'effective_unit_price', 'type' => 'float', 'optional' => true],
                ['name' => 'unit_label', 'type' => 'string', 'index' => false, 'optional' => true],
                ['name' => 'discount_pct', 'type' => 'float', 'optional' => true],
                ['name' => 'promo_type', 'type' => 'string', 'facet' => true],
                ['name' => 'promo_label', 'type' => 'string', 'optional' => true],
                // Promotion details for the shopping-plan maths (@offer/basket lineCost).
                ['name' => 'promo_qty', 'type' => 'int32', 'index' => false, 'optional' => true],
                ['name' => 'promo_total', 'type' => 'float', 'index' => false, 'optional' => true],
                ['name' => 'promo_buy', 'type' => 'int32', 'index' => false, 'optional' => true],
                ['name' => 'promo_get', 'type' => 'int32', 'index' => false, 'optional' => true],
                ['name' => 'max_per_customer', 'type' => 'int32', 'index' => false, 'optional' => true],
                ['name' => 'card_required', 'type' => 'bool', 'facet' => true],
                ['name' => 'under_review', 'type' => 'bool', 'optional' => true],
                ['name' => 'origin', 'type' => 'string', 'facet' => true, 'optional' => true],
                ['name' => 'valid_from', 'type' => 'int64'],
                ['name' => 'valid_to', 'type' => 'int64'],
                ['name' => 'like_count', 'type' => 'int32'],
                ['name' => 'image', 'type' => 'string', 'index' => false, 'optional' => true],
            ],
            'default_sorting_field' => 'like_count',
        ];
    }

    public static function from(Offer $offer): array
    {
        $offer->loadMissing(self::RELATIONS);
        $variant = $offer->variant;
        $product = $variant->product;
        $group = $product->productGroup;
        $category = $product->category;

        $comparison = rtrim(rtrim((string) $group->comparison_quantity, '0'), '.');
        $params = $offer->promo_params ?? [];

        $document = [
            'id' => (string) $offer->id,
            'product_id' => $product->id,
            'product_slug' => $product->slug,
            'product_name' => $product->getTranslation('name', 'en'),
            'product_name_ar' => $product->getTranslation('name', 'ar', false) ?: null,
            'variant_id' => $variant->id,
            'variant_slug' => $variant->slug,
            'variant_label' => $variant->optionValues->pluck('label')->implode(', ') ?: $variant->slug,
            'brand' => $product->brand?->getTranslation('name', 'en'),
            'product_group' => $group->getTranslation('name', 'en'),
            'product_group_id' => $group->id,
            // The category and all its ancestors, so filtering on a parent finds its children's offers.
            'category_ids' => array_map('intval', [...array_filter(explode('/', $category->path)), $category->id]),
            'chain_id' => $offer->chain_id,
            'chain_name' => $offer->chain->getTranslation('name', 'en'),
            'chain_slug' => $offer->chain->slug,
            'brochure_id' => $offer->brochure_id,
            'price' => (float) $offer->price,
            'regular_price' => $offer->regular_price !== null ? (float) $offer->regular_price : null,
            'effective_unit_price' => $offer->effective_unit_price !== null ? (float) $offer->effective_unit_price : null,
            'unit_label' => 'per '.($comparison === '1' ? '' : $comparison.' ').$group->comparison_unit_code,
            'discount_pct' => $offer->discount_pct !== null ? (float) $offer->discount_pct : null,
            'promo_type' => $offer->promo_type->value,
            'promo_label' => $offer->getTranslation('promo_label', 'en', false) ?: null,
            'promo_qty' => $offer->promo_type === PromoType::Multibuy ? (int) ($params['qty'] ?? 0) ?: null : null,
            'promo_total' => $offer->promo_type === PromoType::Multibuy && isset($params['total']) ? (float) $params['total'] : null,
            'promo_buy' => $offer->promo_type === PromoType::Bogo ? (int) ($params['buy'] ?? 0) ?: null : null,
            'promo_get' => $offer->promo_type === PromoType::Bogo ? (int) ($params['get'] ?? 0) ?: null : null,
            'max_per_customer' => isset($offer->conditions['max_per_customer']) ? (int) $offer->conditions['max_per_customer'] : null,
            'card_required' => (bool) ($offer->conditions['card_required'] ?? false),
            // Three shopper reports of a wrong price or expiry show "being checked" until staff review.
            'under_review' => ($offer->conditions['under_review'] ?? false) ?: null,
            'origin' => $variant->attributes['origin'] ?? $product->attributes['origin'] ?? null,
            'valid_from' => $offer->valid_from->startOfDay()->getTimestamp(),
            'valid_to' => $offer->valid_to->endOfDay()->getTimestamp(),
            'like_count' => (int) $offer->like_count,
            // Base URL of the first processed image; the site appends "-{width}.avif|webp".
            'image' => ($image = $product->images->whereNotNull('width')->first()) ? GenerateImageSizes::baseUrl($image->path) : null,
        ];

        return array_filter($document, fn ($value) => $value !== null);
    }
}
