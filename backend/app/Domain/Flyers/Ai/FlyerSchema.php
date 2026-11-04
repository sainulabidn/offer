<?php

namespace App\Domain\Flyers\Ai;

use App\Domain\Offers\PromoType;

/**
 * The JSON every provider must answer with, one object per page. Keys are short because output tokens are
 * most of the bill; each key's description tells the model what it means. Only features both providers
 * accept are used (no min/max): ranges are checked in PHP (ItemValidator).
 */
final class FlyerSchema
{
    public const array PAGE_TYPES = ['offers', 'cover', 'info', 'mixed'];

    /** name => [JSON type, may be null, description] */
    private const array ITEM = [
        'name' => ['string', false, 'Product name as printed, with the brand, without the pack size. English when printed in English, else as printed.'],
        'ar' => ['string', true, 'Arabic product name as printed, only when asked for Arabic names; else null.'],
        'brand' => ['string', true, 'Brand, when one is printed or on the pack.'],
        'size' => ['string', true, 'Pack size exactly as printed: "1.5 L", "6 x 200 ml", "500 g", "30 pcs", "per kg".'],
        'price' => ['number', false, 'AED the shopper pays. Multi-buy ("2 for 10"): the total, 10.'],
        'was' => ['number', true, 'The old or regular price when printed (struck out, "was", "before", "regular").'],
        'promo' => ['string', false, 'price: plain price. was_now: price with an old price. multibuy: n for a total. bogo: buy n get m free. percent_off: % off. card_price: needs the store\'s loyalty card. bundle: items sold together. gift: free gift with it. coupon: needs a coupon.'],
        'qty' => ['integer', true, 'multibuy: how many for the price. bogo: how many to buy.'],
        'free' => ['integer', true, 'bogo: how many free.'],
        'pct' => ['number', true, 'percent_off: the percentage.'],
        'limit' => ['integer', true, 'Most a customer may buy, when printed.'],
        'card' => ['boolean', false, 'The price needs the store\'s loyalty or club card.'],
        'note' => ['string', true, 'Short printed promotion or condition text, e.g. "Buy 2 get 1 free", "Max 3 per customer".'],
        'barcode' => ['string', true, 'Barcode digits, only when printed.'],
        'box' => ['array', true, 'Where the offer is: [left, top, right, bottom] in thousandths of the picture width and height, 0 to 1000.'],
        'sure' => ['number', false, 'How sure you are of the name, size and price together, 0 to 1. Lower it when the price could belong to another product or text is hard to read.'],
    ];

    public const array ITEM_FIELDS = ['name', 'ar', 'brand', 'size', 'price', 'was', 'promo', 'qty', 'free', 'pct', 'limit', 'card', 'note', 'barcode', 'box', 'sure'];

    /**
     * @param  bool  $allRequired  OpenAI's strict mode lists every key as required and allows null instead;
     *                             Claude leaves the nullable keys out of "required".
     */
    public static function schema(bool $allRequired = false): array
    {
        $item = self::object(self::ITEM, $allRequired);
        $item['properties']['promo']['enum'] = array_map(fn (PromoType $type) => $type->value, PromoType::cases());
        $item['properties']['box']['items'] = ['type' => 'integer'];

        $page = self::object([
            'page' => ['string', false, 'offers: mostly offers. cover, info: no offers (front page, store list, terms). mixed: some.'],
            'from' => ['string', true, 'First day the offers are valid, as YYYY-MM-DD, when printed.'],
            'until' => ['string', true, 'Last day the offers are valid, as YYYY-MM-DD, when printed.'],
            'where' => ['array', false, 'Stores, branches, areas or emirates printed as where these offers apply. Empty when not printed.'],
            'items' => ['array', false, 'Every offer on the page.'],
        ], $allRequired);
        $page['properties']['page']['enum'] = self::PAGE_TYPES;
        $page['properties']['where']['items'] = ['type' => 'string'];
        $page['properties']['items']['items'] = $item;

        return $page;
    }

    /** @param  array<string, array{0: string, 1: bool, 2: string}>  $fields */
    private static function object(array $fields, bool $allRequired): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $name => [$type, $nullable, $description]) {
            $properties[$name] = ['type' => $nullable && $allRequired ? [$type, 'null'] : $type, 'description' => $description];
            if (! $nullable || $allRequired) {
                $required[] = $name;
            }
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }
}
