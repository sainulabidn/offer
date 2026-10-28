<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * Search-only passthrough to a Typesense that listens on localhost (cPanel servers can't expose it).
 * Only document search is forwarded; Typesense itself checks the scoped, read-only key sent by the caller.
 */
Route::get('collections/{collection}/documents/search', function (Request $request, string $collection) {
    $key = $request->header('X-TYPESENSE-API-KEY') ?? $request->query('x-typesense-api-key');
    abort_if(blank($key), 401);

    $settings = config('scout.typesense.client-settings.nodes.0');
    $response = Http::timeout(5)
        ->withHeaders(['X-TYPESENSE-API-KEY' => $key])
        ->get("{$settings['protocol']}://{$settings['host']}:{$settings['port']}/collections/{$collection}/documents/search", $request->except('x-typesense-api-key'));

    return response($response->body(), $response->status())
        ->header('Content-Type', 'application/json')
        ->header('Cache-Control', $response->successful() ? 'public, max-age=30, s-maxage=60' : 'no-store');
})->where('collection', '[a-z0-9_]+');
