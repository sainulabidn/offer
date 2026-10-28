<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Category;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Retail\Chain;
use App\Domain\Search\SearchKeys;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    /** Category tree for menus and filters. */
    public function categories(): JsonResponse
    {
        $categories = Category::query()->where('is_active', true)->orderBy('sort')->get();
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

    /** Every public URL the sitemap lists, with last-changed dates. */
    public function sitemap(): JsonResponse
    {
        return response()->json([
            'products' => Product::query()->where('status', ProductStatus::Active)->get(['slug', 'updated_at'])
                ->map(fn (Product $p) => ['slug' => $p->slug, 'updated' => $p->updated_at->toDateString()]),
            'chains' => Chain::query()->where('is_active', true)->pluck('slug'),
            'categories' => Category::query()->where('is_active', true)->pluck('slug'),
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
