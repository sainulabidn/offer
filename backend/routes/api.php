<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ChainController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\EngagementController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public, cacheable reads. The website renders at the edge and caches these, so the limit is generous.
    Route::middleware('throttle:public-read')->group(function () {
        Route::get('search-config', [CatalogController::class, 'searchConfig']);
        Route::get('categories', [CatalogController::class, 'categories']);
        Route::get('chains', [ChainController::class, 'index']);
        Route::get('chains/{slug}', [ChainController::class, 'show']);
        Route::get('products/{slug}', [ProductController::class, 'show']);
        Route::get('directory', DirectoryController::class);
        Route::get('sitemap', [CatalogController::class, 'sitemap']);
    });

    // Sign-in by emailed code.
    Route::post('auth/code', [AuthController::class, 'sendCode'])->middleware('throttle:login-code');
    Route::post('auth/verify', [AuthController::class, 'verify'])->middleware('throttle:login-verify');

    // Reports are open to guests (Turnstile-checked), but rate limited.
    Route::post('reports', [EngagementController::class, 'report'])->middleware('throttle:reports');

    Route::middleware(['auth:sanctum', 'throttle:account'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('likes', [EngagementController::class, 'likes']);
        Route::post('likes', [EngagementController::class, 'toggleLike']);
    });
});
