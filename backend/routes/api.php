<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ChainController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\EngagementController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\PasskeyController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SeoController;
use App\Http\Controllers\Api\V1\ShareImageController;
use App\Http\Controllers\Api\V1\ShopperController;
use App\Http\Controllers\Api\V1\TrendingController;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::prefix('v1')->group(function () {
    // Public, cacheable reads. The website renders at the edge and caches these, so the limit is generous.
    Route::middleware('throttle:public-read')->group(function () {
        Route::get('search-config', [CatalogController::class, 'searchConfig']);
        Route::get('categories', [CatalogController::class, 'categories']);
        Route::get('chains', [ChainController::class, 'index']);
        Route::get('chains/{slug}', [ChainController::class, 'show']);
        Route::get('products/{slug}', [ProductController::class, 'show']);
        Route::get('products/{slug}/history', [ProductController::class, 'history']);
        Route::get('directory', DirectoryController::class);
        Route::get('places', [ShopperController::class, 'placeNames']);
        Route::get('trending', TrendingController::class);
        Route::get('sitemap', [CatalogController::class, 'sitemap']);
        Route::get('brands', [CatalogController::class, 'brands']);
        Route::get('seo', SeoController::class);
    });

    // Social sharing pictures, drawn on request; each new address costs a render, so a tighter limit.
    Route::middleware('throttle:share-images')->group(function () {
        Route::get('og/product/{slug}.jpg', [ShareImageController::class, 'product'])->where('slug', '[a-z0-9-]+');
        Route::get('og/list.jpg', [ShareImageController::class, 'listing']);
    });

    // Sign-in by emailed code.
    Route::post('auth/code', [AuthController::class, 'sendCode'])->middleware('throttle:login-code');
    Route::post('auth/verify', [AuthController::class, 'verify'])->middleware('throttle:login-verify');

    // Passkeys (laravel/passkeys). The challenge is kept in the session between the two requests.
    Route::middleware(config('passkeys.throttle'))->group(function () {
        Route::get('auth/passkey/options', [PasskeyLoginController::class, 'index']);
        Route::post('auth/passkey', [PasskeyLoginController::class, 'store']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('passkeys', [PasskeyController::class, 'index']);
            Route::get('passkeys/options', [PasskeyRegistrationController::class, 'index']);
            Route::post('passkeys', [PasskeyRegistrationController::class, 'store']);
            Route::delete('passkeys/{passkey}', [PasskeyRegistrationController::class, 'destroy']);
        });
    });

    // Anonymous counts. Without the stateful middleware there is no session row, cookie or CSRF check per beacon.
    Route::post('events', EventController::class)
        ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
        ->middleware('throttle:events');

    // Reports are open to guests (Turnstile-checked), but rate limited.
    Route::post('reports', [EngagementController::class, 'report'])->middleware('throttle:reports');

    // Anyone with a plan's share link can view it.
    Route::get('shared-plans/{token}', [PlanController::class, 'shared'])->middleware('throttle:public-read');

    Route::middleware(['auth:sanctum', 'throttle:account'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('likes', [EngagementController::class, 'likes']);
        Route::post('likes', [EngagementController::class, 'toggleLike']);
        Route::get('alerts', [AlertController::class, 'index']);
        Route::post('alerts', [AlertController::class, 'store']);
        Route::delete('alerts/{alert}', [AlertController::class, 'destroy']);
        Route::get('plans', [PlanController::class, 'index']);
        Route::put('plans/{id}', [PlanController::class, 'upsert']);
        Route::delete('plans/{plan}', [PlanController::class, 'destroy']);
        Route::post('plans/{plan}/share', [PlanController::class, 'share']);
        Route::post('shared-plans/{token}/join', [PlanController::class, 'join']);
        Route::get('me/preferences', [ShopperController::class, 'preferences']);
        Route::put('me/preferences', [ShopperController::class, 'savePreferences']);
        Route::get('me/activity', [ShopperController::class, 'activity']);
        Route::post('me/activity', [ShopperController::class, 'addActivity']);
        Route::delete('me/activity', [ShopperController::class, 'forgetActivity']);
        Route::get('me/export', [ShopperController::class, 'export']);
        Route::get('me/places', [ShopperController::class, 'places']);
        Route::delete('me/places', [ShopperController::class, 'deletePlaces']);
        Route::put('me/places/{id}', [ShopperController::class, 'savePlace']);
        Route::delete('me/places/{id}', [ShopperController::class, 'deletePlace']);
    });
});
