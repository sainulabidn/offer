<?php

namespace App\Filament\Imports;

use App\Domain\Access\Access;
use App\Domain\Catalog\Category;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The rows of an offer sheet: one per chosen store and pack, with the branches, dates, times and shared
 * texts filled in, ready for prices. The Offer builder page edits them in place; they can also be
 * downloaded as a CSV for OfferImporter. Only stores, branches and packs the person may use are included.
 */
final class OfferSheet
{
    /** More rows than this is too much to price by hand; narrow the products instead. */
    public const int MAX_ROWS = 20_000;

    public static function csv(string $name, array $rows): StreamedResponse
    {
        return OfferImportGuide::download($name, array_keys(OfferImportGuide::COLUMNS), array_map(fn (array $row) => array_values(self::csvRow($row)), $rows));
    }

    /** @return array<string, string> a row as its CSV cells (OfferImportGuide::COLUMNS); other keys are for the Offer builder */
    public static function csvRow(array $row): array
    {
        return array_map(
            fn (string $column) => is_bool($row[$column] ?? null) ? ($row[$column] ? 'yes' : '') : (string) ($row[$column] ?? ''),
            array_combine(array_keys(OfferImportGuide::COLUMNS), array_keys(OfferImportGuide::COLUMNS)),
        );
    }

    /** "3 stores × 412 packs = 1,236 rows", or what is missing. */
    public static function summary(User $user, array $options, int $max = self::MAX_ROWS): string
    {
        $stores = count(self::stores($user, $options));
        if ($stores === 0) {
            return 'Choose at least one store.';
        }
        $packs = self::packs($options)->count();
        $rows = $stores * $packs;

        return sprintf('%d %s × %s %s = %s rows%s', $stores, str('store')->plural($stores), number_format($packs), str('pack')->plural($packs), number_format($rows),
            $rows > $max ? '. That is too many: choose fewer products (at most '.number_format($max).' rows).' : '');
    }

    /**
     * @param  array<string, mixed>  $options  chain_ids, branch_ids, valid_from, valid_to, start_time, end_time,
     *                                         promotion_text, limit_text, limit_text_ar, max_per_customer, employees_only,
     *                                         loyalty_card, category_ids, brand_ids, product_group_ids, search, only_priced
     * @return list<array<string, mixed>> CSV columns plus chain_id, variant_id, branch_ids, store_name and branch_label
     *
     * @throws RuntimeException when there are too many rows or nothing to put in them
     */
    public static function rows(User $user, array $options, int $max = self::MAX_ROWS): array
    {
        $stores = self::stores($user, $options);
        if ($stores === []) {
            throw new RuntimeException('Choose at least one of your stores.');
        }
        $packs = self::packs($options);
        if ($packs->doesntExist()) {
            throw new RuntimeException('No products match these filters.');
        }
        if ($packs->count() * count($stores) > $max) {
            throw new RuntimeException('That is more than '.number_format($max).' rows. Choose fewer products or stores.');
        }

        $usual = self::usualPrices(array_keys($stores));
        $shared = [
            'from' => (string) $options['valid_from'],
            'until' => (string) $options['valid_to'],
            'from_time' => self::clock($options['start_time'] ?? null),
            'until_time' => self::clock($options['end_time'] ?? null),
            'promotion_text' => trim((string) ($options['promotion_text'] ?? '')),
            'limit_text' => trim((string) ($options['limit_text'] ?? '')),
            'limit_text_ar' => trim((string) ($options['limit_text_ar'] ?? '')),
            'max_per_customer' => (string) ($options['max_per_customer'] ?? ''),
            'employees_only' => (bool) ($options['employees_only'] ?? false),
            'loyalty_card' => (bool) ($options['loyalty_card'] ?? false),
        ];

        $rows = [];
        foreach ($packs->lazy() as $variant) {
            foreach ($stores as $chainId => [$chain, $branches]) {
                $price = $usual[$chainId][$variant->id] ?? null;
                if (($options['only_priced'] ?? false) && $price === null) {
                    continue;
                }
                $rows[] = [
                    'chain_id' => $chainId,
                    'variant_id' => $variant->id,
                    'branch_ids' => $branches->modelKeys(),
                    'store_name' => $chain->name,
                    'branch_label' => $branches->isEmpty() ? 'All branches' : $branches->pluck('name')->implode(', '),
                    'store' => $chain->slug,
                    'branches' => $branches->pluck('slug')->implode('|'),
                    'barcode' => $variant->gtin ?? '',
                    'product' => $variant->gtin ? '' : $variant->product->slug,
                    'pack' => $variant->gtin ? '' : $variant->slug,
                    'name' => $variant->label(),
                    'usual_price' => $price ?? '',
                    ...$shared,
                ];
            }
        }

        return $rows;
    }

    /**
     * The chosen stores the person may add offers for, each with its branches: the chosen ones of that store,
     * none (every branch) for those who manage the whole store, or else all of their branches there.
     *
     * @return array<int, array{0: Chain, 1: \Illuminate\Database\Eloquent\Collection<int, Branch>}>
     */
    private static function stores(User $user, array $options): array
    {
        $picked = collect($options['branch_ids'] ?? [])->map(fn ($id) => (int) $id);
        $stores = [];
        foreach (OfferImportGuide::chains($user)->whereKey($options['chain_ids'] ?? [])->orderBy('slug')->get() as $chain) {
            $mine = OfferImportGuide::branches($user, $chain)->get();
            $chosen = $mine->whereIn('id', $picked);
            $wholeStore = Access::allows($user, 'chains', 'edit', $chain);

            $branches = match (true) {
                $chosen->isNotEmpty() => $chosen->values(),
                $wholeStore => $mine->take(0),
                default => $mine,
            };
            if ($branches->isEmpty() && ! $wholeStore) {
                continue; // none of this store's branches are theirs
            }
            $stores[$chain->id] = [$chain, $branches];
        }

        return $stores;
    }

    /** Packs shoppers can see, narrowed by category (with its subcategories), brand, product group and name. */
    public static function packs(array $options): Builder
    {
        $categories = collect($options['category_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        $search = trim((string) ($options['search'] ?? ''));

        return OfferImportGuide::packs()
            ->when($categories->isNotEmpty(), fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p->whereIn('category_id', Category::query()
                ->whereIn('id', $categories)
                ->orWhere(fn (Builder $c) => $categories->each(fn (int $id) => $c->orWhere('path', 'like', "%/{$id}/%")))
                ->select('id'))))
            ->when($options['brand_ids'] ?? [], fn (Builder $q, array $ids) => $q->whereHas('product', fn (Builder $p) => $p->whereIn('brand_id', $ids)))
            ->when($options['product_group_ids'] ?? [], fn (Builder $q, array $ids) => $q->whereHas('product', fn (Builder $p) => $p->whereIn('product_group_id', $ids)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('product', fn (Builder $p) => $p->whereRaw("name->>'en' ilike ?", ['%'.$search.'%']))
                ->orWhere('gtin', $search)));
    }

    /** @return array<int, array<int, string>> chain id → pack id → the latest shelf price there */
    private static function usualPrices(array $chainIds): array
    {
        $prices = [];
        DB::table('regular_prices')
            ->selectRaw('DISTINCT ON (product_variant_id, chain_id) product_variant_id, chain_id, price')
            ->whereIn('chain_id', $chainIds)
            ->orderBy('product_variant_id')->orderBy('chain_id')->orderByDesc('observed_at')
            ->get()
            ->each(function ($row) use (&$prices) {
                $prices[$row->chain_id][$row->product_variant_id] = (string) $row->price;
            });

        return $prices;
    }

    private static function clock(?string $time): string
    {
        return $time ? substr($time, 0, 5) : '';
    }

    /** @return Collection<int, string> branch id → "Chain · Branch", for the chosen stores, the person's own only */
    public static function branchOptions(User $user, array $chainIds): Collection
    {
        // Built key by key: flatMap() would renumber the branch ids to 0, 1, 2…
        $options = [];
        foreach (OfferImportGuide::chains($user)->whereKey($chainIds)->orderBy('slug')->get() as $chain) {
            foreach (OfferImportGuide::branches($user, $chain)->get() as $branch) {
                $options[$branch->id] = "{$chain->name} · {$branch->name}";
            }
        }

        return collect($options);
    }
}
