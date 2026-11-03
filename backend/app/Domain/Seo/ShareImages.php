<?php

namespace App\Domain\Seo;

use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\GenerateImageSizes;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductImage;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Geo\Region;
use App\Domain\Offers\Offer;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The pictures shown when a page is shared on WhatsApp, Facebook, X or LinkedIn: a product with its
 * cheapest price today, or a list (a place, category, brand or landing page) with its offer count and best
 * discount. Everything on them comes from our own data, never from the request, so nobody can make
 * a picture on our domain say something else.
 */
final class ShareImages
{
    private const string CANVAS = '#faf7f0';

    private const string SURFACE = '#ffffff';

    private const string INK = '#17231d';

    private const string MUTED = '#56635b';

    private const string PRIMARY = '#0f5c3f';

    private const string PRIMARY_SOFT = '#e2f1e9';

    private const string SAVE = '#c8432b';

    private const string ON_PRIMARY = '#fdfbf6';

    private const string ON_PRIMARY_MUTED = '#bfe3cf';

    /** The text column beside a product's photo. */
    private const int TEXT_WIDTH = 518;

    /** The product, or one of its packs, at its cheapest today; only stores in $region when given. */
    public function product(Product $product, ?ProductVariant $pack, ?Region $region): string
    {
        $offers = Offer::query()->onNow()->forShoppers()
            ->whereHas('variant', fn ($q) => $pack ? $q->whereKey($pack->id) : $q->where('product_id', $product->id))
            ->when($region, fn ($q) => $q->whereHas('brochure.branches', fn ($b) => $b->where('branches.is_active', true)->whereIn('region_id', self::regionIds($region))))
            ->with(['chain', 'variant.optionValues'])
            ->orderBy('price')
            ->get();
        $best = $offers->first();
        $place = $region ? self::placeName($region) : 'the UAE';

        $card = new ShareCard(self::CANVAS);
        $settings = SeoSettings::all();

        // The photo on a white tile, or the first letter when there is none.
        $card->box(56, 56, 518, 518, self::SURFACE, 32);
        $image = $product->images()->whereNotNull('width')->first();
        if (! $image || ! $this->drawPhoto($card, $image, 95, 95, 440)) {
            $card->box(56, 56, 518, 518, self::PRIMARY_SOFT, 32);
            $letter = mb_strtoupper(mb_substr($product->name, 0, 1));
            $card->text($letter, 315 - intdiv($card->width($letter, 220, 'ExtraBold'), 2), 395, 220, 'ExtraBold', self::PRIMARY);
        }

        $x = 626;
        $width = self::TEXT_WIDTH;
        $y = 96;
        if ($product->brand) {
            $card->text(mb_strtoupper($product->brand->name), $x, $y, 22, 'Bold', self::PRIMARY);
            $y += 18;
        }
        foreach ($card->wrap($product->name, 46, 'ExtraBold', $width, 3) as $line) {
            $y += 54;
            $card->text($line, $x, $y, 46, 'ExtraBold', self::INK);
        }
        $packLabel = $pack ? self::packLabel($pack) : ($best && $product->variants()->count() > 1 ? self::packLabel($best->variant) : null);
        if ($packLabel) {
            $y += 42;
            $card->text($packLabel, $x, $y, 28, 'SemiBold', self::MUTED);
        }

        if ($best) {
            $y += 100;
            $priceX = $x + $card->text('AED', $x, $y - 4, 30, 'Bold', self::INK) + 10;
            $priceX += $card->text(number_format((float) $best->price, 2), $priceX, $y, 80, 'ExtraBold', self::INK) + 18;
            if ($best->discount_pct >= 1) {
                $badge = '-'.round((float) $best->discount_pct).'%';
                $badgeWidth = $card->width($badge, 28, 'ExtraBold') + 28;
                $card->box($priceX, $y - 52, $badgeWidth, 50, self::SAVE, 25);
                $card->text($badge, $priceX + 14, $y - 15, 28, 'ExtraBold', '#ffffff');
            }
            if ($best->regular_price && $best->regular_price > $best->price) {
                $y += 40;
                $was = 'was '.number_format((float) $best->regular_price, 2);
                $wasWidth = $card->text($was, $x, $y, 24, 'Regular', self::MUTED);
                $card->line($x + 46, $x + $wasWidth, $y - 9, self::MUTED);
            }
            $y += 46;
            foreach ($card->wrap("at {$best->chain->name} in {$place}", 26, 'SemiBold', $width, 2) as $line) {
                $card->text($line, $x, $y, 26, 'SemiBold', self::INK);
                $y += 34;
            }
            $stores = $offers->pluck('chain_id')->unique()->count();
            $card->text(($stores > 1 ? "Compare {$stores} stores · " : '').'until '.$best->valid_to->format('j M'), $x, $y + 2, 22, 'Regular', self::MUTED);
        } else {
            $y += 64;
            foreach ($card->wrap("Compare prices in {$place}", 30, 'Bold', $width, 2) as $line) {
                $card->text($line, $x, $y, 30, 'Bold', self::INK);
                $y += 40;
            }
            $card->text('Get an alert when it goes on offer', $x, $y, 24, 'Regular', self::MUTED);
        }

        $this->footer($card, $x, 574, $settings['site_name'], self::PRIMARY, self::MUTED);

        return $card->jpeg();
    }

    /**
     * A list of offers: in a place, of a category or brand, or what a landing page saved. The heading of a
     * landing page is staff-written; everything else is built from names in our own tables.
     */
    public function listing(?Region $region, ?Category $category, ?Brand $brand, ?SeoPage $landing): string
    {
        $filters = $landing?->filters ?? [];
        $region ??= isset($filters['region_id']) ? Region::find($filters['region_id']) : null;
        $category ??= isset($filters['category_id']) ? Category::find($filters['category_id']) : null;
        $brand ??= count($filters['brand_ids'] ?? []) === 1 ? Brand::find($filters['brand_ids'][0]) : null;

        $filterBy = array_filter([
            'staff_only:false',
            $region ? "region_ids:={$region->id}" : null,
            $category ? "category_ids:={$category->id}" : null,
            $brand ? "brand_id:={$brand->id}" : null,
            ! empty($filters['brand_ids']) && ! $brand ? 'brand_id:['.implode(',', $filters['brand_ids']).']' : null,
            ! empty($filters['chain_ids']) ? 'chain_id:['.implode(',', $filters['chain_ids']).']' : null,
            ! empty($filters['product_group_id']) ? "product_group_id:={$filters['product_group_id']}" : null,
            ! empty($filters['min_discount']) ? "discount_pct:>={$filters['min_discount']}" : null,
            ! empty($filters['card_only']) ? 'card_required:true' : null,
        ]);

        try {
            $result = Offer::search($filters['q'] ?? '*')->options([
                'filter_by' => implode(' && ', $filterBy),
                'facet_by' => 'chain_id',
                'max_facet_values' => 200,
                'sort_by' => 'discount_pct:desc',
                'per_page' => 30,
            ])->raw();
        } catch (Throwable) {
            $result = ['found' => 0, 'hits' => [], 'facet_counts' => []];
        }
        $found = (int) ($result['found'] ?? 0);
        $stores = count($result['facet_counts'][0]['counts'] ?? []);
        $top = (int) round((float) ($result['hits'][0]['document']['discount_pct'] ?? 0));

        $place = $region ? self::placeName($region) : 'the UAE';
        $title = $landing?->getTranslation('heading', 'en', false)
            ?: $landing?->getTranslation('title', 'en', false)
            ?: (($category?->name ?? $brand?->name ?? 'Supermarket').' offers in '.$place);

        $card = new ShareCard(self::PRIMARY);
        $settings = SeoSettings::all();
        $x = 64;
        $width = 560;

        $card->text("This week in {$place}", $x, 104, 26, 'SemiBold', self::ON_PRIMARY_MUTED);
        $y = 110;
        foreach ($card->wrap($title, 54, 'ExtraBold', $width, 3) as $line) {
            $y += 66;
            $card->text($line, $x, $y, 54, 'ExtraBold', self::ON_PRIMARY);
        }
        if ($found > 0) {
            $y += 58;
            $card->text($found.' '.($found === 1 ? 'deal' : 'deals').' from '.$stores.' '.($stores === 1 ? 'store' : 'stores'), $x, $y, 30, 'SemiBold', self::ON_PRIMARY);
            if ($top >= 5) {
                $y += 34;
                $label = "Up to {$top}% off";
                $card->box($x, $y, $card->width($label, 30, 'ExtraBold') + 40, 60, self::SAVE, 30);
                $card->text($label, $x + 20, $y + 42, 30, 'ExtraBold', '#ffffff');
            }
        } else {
            $y += 58;
            $card->text('Compared by true price per unit', $x, $y, 28, 'SemiBold', self::ON_PRIMARY);
        }

        $this->footer($card, $x, 574, $settings['site_name'], self::ON_PRIMARY, self::ON_PRIMARY_MUTED);

        // The first four products on the list as a 2 × 2 grid: the photo, or its initial and name without one.
        $products = collect($result['hits'] ?? [])->pluck('document')->unique('product_id')->take(4)->values();
        $photos = $this->photosFor($products->pluck('product_id'));
        foreach ($products as $index => $product) {
            $tileX = 660 + ($index % 2) * 254;
            $tileY = 73 + intdiv($index, 2) * 254;
            $card->box($tileX, $tileY, 230, 230, self::SURFACE, 26);
            $photo = $photos->get($product['product_id']);
            if (! $photo || ! $this->drawPhoto($card, $photo, $tileX + 15, $tileY + 15, 200)) {
                $letter = mb_strtoupper(mb_substr($product['product_name'], 0, 1));
                $card->text($letter, $tileX + 115 - intdiv($card->width($letter, 96, 'ExtraBold'), 2), $tileY + 125, 96, 'ExtraBold', self::PRIMARY);
                $lineY = $tileY + 168;
                foreach ($card->wrap($product['product_name'], 20, 'SemiBold', 196, 2) as $line) {
                    $card->text($line, $tileX + 115 - intdiv($card->width($line, 20, 'SemiBold'), 2), $lineY, 20, 'SemiBold', self::INK);
                    $lineY += 26;
                }
            }
        }

        return $card->jpeg();
    }

    /** The site name and what it does, along the bottom. */
    private function footer(ShareCard $card, int $x, int $y, string $site, string $strong, string $soft): void
    {
        $nameWidth = $card->text($site, $x, $y, 34, 'ExtraBold', $strong);
        $card->text('Compare supermarket prices in the UAE', $x + $nameWidth + 16, $y - 2, 22, 'SemiBold', $soft);
    }

    /**
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, ProductImage> product id → its first processed photo
     */
    private function photosFor(Collection $productIds): Collection
    {
        return ProductImage::query()->whereIn('product_id', $productIds)->whereNotNull('width')->orderBy('id')->get()->unique('product_id')->keyBy('product_id');
    }

    private function drawPhoto(ShareCard $card, ProductImage $image, int $x, int $y, int $size): bool
    {
        try {
            $bytes = GenerateImageSizes::disk()->get($image->path);
        } catch (Throwable) {
            return false;
        }

        return $bytes !== null && $card->photo($bytes, $x, $y, $size);
    }

    /** This region and, for an emirate, its areas: branches are filed under either. */
    private static function regionIds(Region $region): array
    {
        return [$region->id, ...Region::query()->where('parent_id', $region->id)->pluck('id')];
    }

    private static function placeName(Region $region): string
    {
        return $region->parent ? "{$region->name}, {$region->parent->name}" : $region->name;
    }

    private static function packLabel(ProductVariant $variant): string
    {
        return $variant->optionValues->pluck('label')->implode(', ') ?: $variant->slug;
    }
}
