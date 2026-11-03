<?php

namespace App\Filament\Imports;

use App\Domain\Access\Access;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use League\Csv\Bom;
use League\Csv\Writer;
use SplTempFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Help for the offer CSV import (OfferImporter): what each column means, a sample made from the person's
 * own store, branches and real packs, and lists of their stores and of every pack to copy codes from.
 */
final class OfferImportGuide
{
    /** Column => what to put in it. Shown while matching columns, and in the "How to import" guide. */
    public const array COLUMNS = [
        'store' => 'Required. The store\'s code (slug), e.g. lulu-hypermarket. See "Your stores and branches".',
        'branches' => 'Branch codes separated by |, e.g. lulu-al-barsha|lulu-marina. Empty = every branch (only for people who manage the whole store).',
        'barcode' => 'The pack\'s barcode. Or leave it empty and fill "product" and "pack".',
        'product' => 'The product\'s code, when there is no barcode. See "Pack list".',
        'pack' => 'The pack\'s code within that product, e.g. 5-kg.',
        'name' => 'For reading only: the product and pack. The import ignores it.',
        'usual_price' => 'For reading only, in an offer sheet: the store\'s last known shelf price. The import ignores it.',
        'price' => 'The offer price in AED, e.g. 45.95. For "2 for 18", the price of one. Rows without a price are skipped.',
        'was_price' => 'The usual price, when the brochure shows one.',
        'promotion' => 'Empty or price, was_now, multibuy (n for X), bogo (buy n get m free), percent_off, card_price, bundle, gift or coupon.',
        'multibuy_qty' => 'For multibuy: how many, e.g. 2 in "2 for 18".',
        'multibuy_total' => 'For multibuy: the total, e.g. 18 in "2 for 18".',
        'buy' => 'For bogo: how many to buy, e.g. 2 in "buy 2 get 1 free".',
        'get_free' => 'For bogo: how many free.',
        'promotion_text' => 'The promotion as shoppers see it, e.g. 2 for 18.',
        'max_per_customer' => 'A number, when the brochure limits how many each customer may buy.',
        'loyalty_card' => 'yes when the price needs the store\'s loyalty card. Empty = no.',
        'employees_only' => 'yes when only the store\'s own employees get this price. Empty = no.',
        'limit_text' => 'The limit as the brochure words it, e.g. Max 3 kg per customer or Min 2 pcs.',
        'limit_text_ar' => 'The same in Arabic.',
        'from' => 'First day, as YYYY-MM-DD. Empty = today.',
        'until' => 'Required. Last day, as YYYY-MM-DD.',
        'from_time' => 'For an offer at certain times only, on each of its days: the start, e.g. 08:00 or 8:00 AM. Empty = all day.',
        'until_time' => 'With from_time: the end, e.g. 11:00 or 11:00 AM. Shoppers see a countdown to it.',
    ];

    /** @var array<int, list<array<string, string>>> sample rows per user id, for one request */
    private static array $samples = [];

    /** @return list<string> one column's values in the sample rows */
    public static function examples(string $column): array
    {
        return array_map(fn (array $row) => $row[$column] ?? '', self::sample(Access::user()));
    }

    /**
     * Three rows for the person's first store: a plain price, a "2 for" with a limit, and an employees-only
     * price at chosen branches. Codes the person can't see are replaced by placeholders.
     *
     * @return list<array<string, string>>
     */
    public static function sample(?User $user): array
    {
        return self::$samples[$user?->id ?? 0] ??= self::buildSample($user);
    }

    private static function buildSample(?User $user): array
    {
        $today = now(config('app.market_timezone'));
        $chain = $user ? self::chains($user)->whereHas('branches', fn (Builder $q) => $q->where('is_active', true))->orderBy('id')->first() : null;
        $branches = $chain ? self::branches($user, $chain)->pluck('slug')->all() : [];
        $wholeStore = $chain && Access::allows($user, 'chains', 'edit', $chain);
        $packs = self::packs()->limit(3)->get()->values();

        $pack = fn (int $i, bool $barcode = false) => isset($packs[$i])
            ? [...($barcode && $packs[$i]->gtin ? ['barcode' => $packs[$i]->gtin] : ['product' => $packs[$i]->product->slug, 'pack' => $packs[$i]->slug]), 'name' => $packs[$i]->label()]
            : ['product' => 'product-code', 'pack' => 'pack-code'];
        $store = ['store' => $chain?->slug ?? 'your-store-code'];
        $someBranches = implode('|', array_slice($branches, 0, 2)) ?: 'branch-code';

        return [
            [...$store, 'branches' => $wholeStore ? '' : ($branches[0] ?? 'branch-code'), ...$pack(0, barcode: true),
                'price' => '45.95', 'was_price' => '54.50', 'until' => $today->copy()->addDays(6)->toDateString()],
            [...$store, 'branches' => $wholeStore ? '' : ($branches[0] ?? 'branch-code'), ...$pack(1),
                'price' => '9.95', 'promotion' => 'multibuy', 'multibuy_qty' => '2', 'multibuy_total' => '18', 'promotion_text' => '2 for 18',
                'max_per_customer' => '4', 'limit_text' => 'Max 4 per customer', 'until' => $today->copy()->addDays(6)->toDateString()],
            [...$store, 'branches' => $someBranches, ...$pack(2),
                'price' => '30.00', 'employees_only' => 'yes', 'limit_text' => 'Max 3 kg per customer', 'limit_text_ar' => 'الحد الأقصى ٣ كجم لكل عميل',
                'from' => $today->toDateString(), 'until' => $today->copy()->addDays(2)->toDateString()],
        ];
    }

    public static function sampleCsv(User $user): StreamedResponse
    {
        $columns = array_keys(self::COLUMNS);

        return self::download('offers-sample.csv', $columns, array_map(
            fn (array $row) => array_map(fn (string $column) => $row[$column] ?? '', $columns),
            self::sample($user),
        ));
    }

    /** The stores and branches the person may add offers for, with their codes. */
    public static function storesCsv(User $user): StreamedResponse
    {
        $rows = [];
        foreach (self::chains($user)->orderBy('slug')->get() as $chain) {
            foreach (self::branches($user, $chain)->get() as $branch) {
                $rows[] = [$chain->slug, $chain->name, $branch->slug, $branch->name];
            }
        }

        return self::download('stores-and-branches.csv', ['store', 'store_name', 'branch', 'branch_name'], $rows);
    }

    /** Every pack shoppers can see, with the codes the import accepts. */
    public static function packsCsv(): StreamedResponse
    {
        $rows = [];
        foreach (self::packs()->lazy() as $variant) {
            $rows[] = [$variant->gtin ?? '', $variant->product->slug, $variant->slug, $variant->label()];
        }

        return self::download('pack-list.csv', ['barcode', 'product', 'pack', 'name'], $rows);
    }

    public static function chains(User $user): Builder
    {
        return Access::scope(Chain::query()->where('is_active', true), $user, 'chains');
    }

    public static function branches(User $user, Chain $chain): Builder
    {
        return Access::scope(Branch::query()->where('chain_id', $chain->id)->where('is_active', true), $user, 'branches')->orderBy('slug');
    }

    public static function packs(): Builder
    {
        return ProductVariant::query()
            ->whereNull('merged_into_id')
            ->whereHas('product', fn (Builder $q) => $q->approved()->where('status', ProductStatus::Active))
            ->with(['product', 'optionValues'])
            ->orderBy('product_id')
            ->orderBy('sort');
    }

    /** UTF-8 with a BOM, so Excel shows Arabic correctly. */
    public static function download(string $name, array $header, array $rows): StreamedResponse
    {
        $csv = Writer::createFromFileObject(new SplTempFileObject);
        $csv->insertOne($header);
        $csv->insertAll($rows);

        return response()->streamDownload(function () use ($csv) {
            $csv->setOutputBOM(Bom::Utf8);
            echo $csv->toString();
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
