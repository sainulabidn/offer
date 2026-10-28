<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Browser/Worker access to Typesense on servers where it isn't public (see routes/typesense.php).
        then: function () {
            if (config('services.typesense.proxy')) {
                Route::prefix('typesense')->middleware('throttle:public-read')->group(base_path('routes/typesense.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The website (another origin in development) signs shoppers in with Sanctum session cookies.
        $middleware->statefulApi();
        // Production sits behind Cloudflare (and a Worker), so trust the forwarded scheme and client IP.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
