<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Seo\SeoSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Site-wide SEO settings and every SEO page (landing pages and overrides); small, and the same for everyone. */
class SeoController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(SeoSettings::forWebsite())
            ->setCache(['public' => true, 'max_age' => 300, 's_maxage' => 300, 'stale_while_revalidate' => 3600]);
    }
}
