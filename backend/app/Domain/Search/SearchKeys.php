<?php

namespace App\Domain\Search;

use App\Domain\Offers\Offer;
use Illuminate\Support\Facades\Cache;
use Typesense\Client;

/**
 * Keys for browsers to query Typesense directly. The parent key (TYPESENSE_SEARCH_KEY) can only search;
 * the scoped key handed to browsers is also locked to the offers collection and expires daily.
 */
final class SearchKeys
{
    public function __construct(private readonly Client $client) {}

    /** One-time setup: create the search-only parent key. Returns its value (shown once by Typesense). */
    public function createSearchOnlyKey(): string
    {
        $key = $this->client->keys->create([
            'description' => 'Search-only key for the public website',
            'actions' => ['documents:search'],
            'collections' => [(new Offer)->searchableAs()],
        ]);

        return $key['value'];
    }

    /** Cached for an hour; valid for a day, so a cached page never holds an expired key. */
    public function scopedKey(): string
    {
        $parent = (string) config('services.typesense.search_key');

        return Cache::remember('typesense:scoped-key', 3600, fn () => $this->client->keys->generateScopedSearchKey($parent, [
            'expires_at' => now()->addDay()->getTimestamp(),
        ]));
    }
}
