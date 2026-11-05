<?php

namespace App\Domain\Flyers\Sources;

/**
 * Nesto's flyers, as its offer site nestooffers.com loads them from admin.nestooffers.com: a PDF per store, plus
 * chain-wide ones under the "Central Offers" store (store 80, location 44). One request lists a store's current
 * offers with their dates, so flyers that start later keep Nesto's dates. The source address names the store:
 * https://admin.nestooffers.com/api/deal-of-the-week?store_id=125&location_id=5
 */
final class NestoOffers
{
    /** @return list<ListedFlyer> */
    public function __invoke(BrochureSource $source, SourceFetcher $http): array
    {
        parse_str((string) parse_url($source->url, PHP_URL_QUERY), $store);
        if (! is_numeric($store['store_id'] ?? null) || ! is_numeric($store['location_id'] ?? null)) {
            throw new FetchFailed('The address must name the Nesto store, e.g. …/deal-of-the-week?store_id=125&location_id=5.');
        }
        $response = $http->post(strtok($source->url, '?'), ['store_id' => (int) $store['store_id'], 'location_id' => (int) $store['location_id'], 'lang_id' => 1]);
        $offers = $response->json('data');
        if (! $response->successful() || ! is_array($offers)) {
            throw new FetchFailed("Nesto's offer site answered HTTP {$response->status()} without a list of offers.", $response->status());
        }

        $flyers = [];
        foreach ($offers as $offer) {
            foreach ($offer['pdfs'] ?? [] as $pdf) {
                if (filled($pdf['pdf_url'] ?? null)) {
                    $flyers[] = new ListedFlyer($pdf['pdf_url'], trim($pdf['title'] ?? $offer['name'] ?? '') ?: null, self::day($offer['start_date'] ?? null), self::day($offer['end_date'] ?? null));
                }
            }
        }

        return $flyers;
    }

    /** "2026-11-08 00:00:00" → "2026-11-08" */
    private static function day(?string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $m) ? $m[0] : null;
    }
}
