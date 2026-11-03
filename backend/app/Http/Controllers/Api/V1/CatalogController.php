<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\GenerateImageSizes;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Search\SearchKeys;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class CatalogController extends Controller
{
    /** Category tree for menus and filters. */
    public function categories(): JsonResponse
    {
        $categories = Category::query()->approved()->where('is_active', true)->orderBy('sort')->get();
        $node = function (Category $category) use (&$node, $categories): array {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'icon' => $category->icon,
                'children' => $categories->where('parent_id', $category->id)->values()->map($node)->all(),
            ];
        };

        return response()->json($categories->whereNull('parent_id')->values()->map($node))
            ->setCache(['public' => true, 'max_age' => 3600, 's_maxage' => 3600]);
    }

    /** Brands with at least one product on the website, for brand pages and the sitemap. */
    public function brands(): JsonResponse
    {
        $brands = Brand::query()->approved()
            ->whereHas('products', fn ($q) => $q->approved()->where('status', ProductStatus::Active))
            ->get()
            ->sortBy(fn (Brand $brand) => mb_strtolower($brand->name));

        return response()->json($brands->values()->map(fn (Brand $brand) => [
            'id' => $brand->id,
            'name' => $brand->name,
            'name_ar' => $brand->getTranslation('name', 'ar', false) ?: null,
            'slug' => $brand->slug,
            'logo' => $brand->logo_path ? Storage::disk(config('filesystems.media_disk'))->url($brand->logo_path) : null,
            'manufacturer' => $brand->manufacturer,
            'private_label' => $brand->is_private_label,
        ]))->setCache(['public' => true, 'max_age' => 3600, 's_maxage' => 3600]);
    }

    /** Every public URL the sitemap lists, with last-changed dates. */
    public function sitemap(): JsonResponse
    {
        return response()->json([
            'products' => Product::query()->approved()->where('status', ProductStatus::Active)
                ->with(['images', 'variants' => fn ($q) => $q->whereNull('merged_into_id')->orderBy('sort')])
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'slug' => $p->slug,
                    'updated' => $p->updated_at->toDateString(),
                    // A product with one pack has no pack pages of its own.
                    'variants' => $p->variants->count() > 1 ? $p->variants->map(fn ($v) => ['id' => $v->id, 'slug' => $v->slug])->all() : [],
                    'image' => ($image = $p->images->whereNotNull('width')->first()) ? GenerateImageSizes::baseUrl($image->path).'-350.webp' : null,
                ]),
            // A store with no branches yet has an empty page, which search engines shouldn't list.
            'chains' => Chain::query()->where('is_active', true)->whereHas('branches', fn ($q) => $q->where('is_active', true))->pluck('slug'),
            'categories' => Category::query()->approved()->where('is_active', true)->pluck('slug'),
            'branches' => Branch::query()->where('is_active', true)
                ->whereHas('chain', fn ($q) => $q->where('is_active', true))
                ->with('chain:id,slug')
                ->get(['id', 'chain_id', 'slug'])
                ->map(fn (Branch $b) => "{$b->chain->slug}/{$b->slug}"),
        ])->setCache(['public' => true, 'max_age' => 3600, 's_maxage' => 3600]);
    }

    /** Where and how browsers query Typesense: public URL, collection and a scoped, expiring search key. */
    public function searchConfig(SearchKeys $keys): JsonResponse
    {
        return response()->json([
            'url' => config('services.typesense.public_url'),
            'collection' => config('scout.prefix').'offers',
            'key' => $keys->scopedKey(),
        ])->setCache(['public' => true, 'max_age' => 3600, 's_maxage' => 3600]);
    }
}
