<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Engagement\Insights;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Product ids rising in one area, for "Trending near you". */
class TrendingController extends Controller
{
    private const int CACHE_SECONDS = 600;

    public function __invoke(Request $request, Insights $insights): JsonResponse
    {
        $region = $request->integer('region') ?: null;

        return response()->json(Cache::remember("trending:{$region}", self::CACHE_SECONDS, fn () => $insights->trending($region)));
    }
}
