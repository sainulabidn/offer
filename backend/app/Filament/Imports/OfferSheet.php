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
 * An offer sheet: a CSV ready for prices, one row per chosen store and pack, with the branches, dates,
 * times and shared texts already filled in. The person types the prices and imports it (OfferImporter);
 * rows left without a price are skipped. Only stores, branches and packs the person may use are included.
 */
final class OfferSheet
{
    /** More rows than this is too much to price by hand; narrow the products instead. */
    public const int MAX_ROWS = 20_000;

    /**
     * @param  array<string, mixed>  $options  chain_ids, branch_ids, valid_from, valid_to, start_time, end_time,
     *                                         promotion_text, limit_text, limit_text_ar, max_per_customer, employees_only,
     *                                         loyalty_card, category_ids, brand_ids, product_group_ids, search, only_priced
     */
    public static function download(User $user, array $options): StreamedResponse
    {
        $rows = self::rows($user, $options);
        $columns = array_keys(OfferImportGuide::COLUMNS);

        return OfferImportGuide::download(
            'offer-sheet-'.$options['valid_from'].'.csv',
            $columns,
            array_map(fn (array $row) => array_map(fn (string $column) => $row[$column] ?? '', $columns), $rows),
        );
    }

    /** "3 stores × 412 packs = 1,236 rows", or what is missing. */
    public static function summary(User $user, array $options): string
    {
        $stores = count(self::stores($user, $options));
        if ($stores === 0) {
            return 'Choose at least one store.';
        }
        $packs = self::packs($options)->count();
        $rows = $stores * $packs;

        return sprintf('%d %s × %s %s = %s rows%s', $stores, str('store')->plural($stores), number_format($packs), str('pack')->plural($packs), number_format($rows),
            $rows > self::MAX_ROWS ? '. That is too many: choose fewer products (at most '.number_format(self::MAX_ROWS).' rows).' : '');
    }

    /**
     * @return list<array<string, string>>
     *
     * @throws RuntimeException when there are too many rows or nothing to put in them
     */
    public static function rows(User $user, array $options): array
    {
        $stores = self::stores($user, $options);
        if ($stores === []) {
            throw new RuntimeException('Choose at least one of your stores.');
        }
        $packs = self::packs($options);
        if ($packs->count() * count($stores) > self::MAX_ROWS) {
            throw new RuntimeException('That is more than '.number_format(self::MAX_ROWS).' rows. Choose fewer products or stores.');
        }

        $usual = self::usualPrices(array_keys($stores));
        $shared = array_filter([
            'from' => (string) $options['valid_from'],
            'until' => (string) $options['valid_to'],
            'from_time' => self::clock($options['start_time'] ?? null),
            'until_time' => self::clock($options['end_time'] ?? null),
            'promotion_text' => trim((string) ($options['promotion_text'] ?? '')),
            'limit_text' => trim((string) ($options['limit_text'] ?? '')),
            'limit_text_ar' => trim((string) ($options['limit_text_ar'] ?? '')),
            'max_per_customer' => (string) ($options['max_per_customer'] ?? ''),
            'employees_only' => ($options['employees_only'] ?? false) ? 'yes' : '',
            'loyalty_card' => ($options['loyalty_card'] ?? false) ? 'yes' : '',
        ], fn ($value) => $value !== '' && $value !== null);

        $rows = [];
        foreach ($packs->lazy() as $variant) {
            foreach ($stores as $chainId => [$chain, $branches]) {
                $price = $usual[$chainId][$variant->id] ?? null;
                if (($options['only_priced'] ?? false) && $price === null) {
                    continue;
                }
                $rows[] = [
                    'store' => $chain->slug,
                    'branches' => $branches,
                    ...($variant->gtin ? ['barcode' => $variant->gtin] : ['product' => $variant->product->slug, 'pack' => $variant->slug]),
                    'name' => $variant->label(),
                    'usual_price' => $price ?? '',
                    ...$shared,
                ];
            }
        }

        return $rows;
    }

    /**
     * The chosen stores the person may add offers for, each with its "branches" cell: the chosen branches of
     * that store, or empty (every branch) for those who manage the whole store, or else all of their branches.
     *
     * @return array<int, array{0: Chain, 1: string}>
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
                $chosen->isNotEmpty() => $chosen,
                $wholeStore => collect(),
                default => $mine,
            };
            if ($branches->isEmpty() && ! $wholeStore) {
                continue; // none of this store's branches are theirs
            }
            $stores[$chain->id] = [$chain, $branches->pluck('slug')->implode('|')];
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

    private static function clock(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }

    /** @return Collection<int, string> branch id → "Chain · Branch", for the chosen stores, the person's own only */
    public static function branchOptions(User $user, array $chainIds): Collection
    {
        return OfferImportGuide::chains($user)->whereKey($chainIds)->orderBy('slug')->get()
            ->flatMap(fn (Chain $chain) => OfferImportGuide::branches($user, $chain)->get()
                ->mapWithKeys(fn (Branch $branch) => [$branch->id => "{$chain->name} · {$branch->name}"]));
    }
}
