<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Geo\Region;
use App\Domain\Seo\SeoPage;
use App\Domain\Seo\ShareImages;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pictures for social sharing (the website serves them at /og/…, cached at the edge). The website adds a
 * version (?v=…) made from the price it shows, so a new price is a new address that WhatsApp and
 * Facebook fetch again; the picture itself is always drawn from today's data.
 */
class ShareImageController extends Controller
{
    /** /og/product/{slug}.jpg?pack=2-l&region=dubai */
    public function product(Request $request, ShareImages $images, string $slug): Response
    {
        $product = Product::query()->approved()->where('status', ProductStatus::Active)->where('slug', $slug)->with('brand')->firstOrFail();
        $pack = $request->filled('pack') ? $product->variants()->whereNull('merged_into_id')->where('slug', $request->query('pack'))->firstOrFail() : null;

        return $this->jpeg($images->product($product, $pack, $this->region($request)));
    }

    /** /og/list.jpg?region=dubai&category=dairy-eggs&brand=almarai, or ?deal=rice-dubai for a landing page */
    public function listing(Request $request, ShareImages $images): Response
    {
        $landing = $request->filled('deal')
            ? SeoPage::query()->where('is_active', true)->where('path', SeoPage::LANDING_PREFIX.$request->query('deal'))->firstOrFail()
            : null;
        $category = $request->filled('category') ? Category::query()->approved()->where('is_active', true)->where('slug', $request->query('category'))->firstOrFail() : null;
        $brand = $request->filled('brand') ? Brand::query()->approved()->where('slug', $request->query('brand'))->firstOrFail() : null;

        return $this->jpeg($images->listing($this->region($request), $category, $brand, $landing));
    }

    private function region(Request $request): ?Region
    {
        return $request->filled('region') ? Region::query()->where('slug', $request->query('region'))->with('parent')->firstOrFail() : null;
    }

    private function jpeg(string $bytes): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            // Versioned addresses: keep a day in browsers and a week at the edge.
            'Cache-Control' => 'public, max-age=86400, s-maxage=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
