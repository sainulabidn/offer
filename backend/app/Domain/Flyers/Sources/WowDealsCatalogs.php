<?php

namespace App\Domain\Flyers\Sources;

use Illuminate\Support\Carbon;

/**
 * A store's flyers on WowDeals (wowdeals.me), a third-party flyer site, for stores whose own flyers can't be
 * fetched: the store page lists each catalogue with its dates and "N Days left" or "Expired", and a
 * catalogue's page shows its pages as pictures. The source address is the store page, e.g.
 * https://www.wowdeals.me/ae/carrefour/catalogs; its link pattern can keep one region's catalogues.
 */
final class WowDealsCatalogs
{
    /** @return list<ListedFlyer> the store's catalogues that haven't expired, newest first */
    public function __invoke(BrochureSource $source, SourceFetcher $http): array
    {
        [$response] = $http->get($source->url);
        if (! $response->successful()) {
            throw new FetchFailed("The WowDeals page answered HTTP {$response->status()}.", $response->status());
        }
        $cards = array_slice(explode('class="product-name"', $response->body()), 1);
        if ($cards === []) {
            throw new FetchFailed('No catalogues found on the WowDeals page; it may have changed.');
        }

        $flyers = [];
        foreach ($cards as $card) {
            if (! preg_match('~<a href="(https://www\.wowdeals\.me/[^"]+/catalogs/[^"]+)">\s*(.*?)\s*</a>~s', $card, $link)
                || ! preg_match('~class="label[^"]*">\s*([^<]+?)\s*<~', $card, $status)
                || stripos($status[1], 'expired') !== false
                || ($source->link_pattern && ! @preg_match('~'.str_replace('~', '\~', $source->link_pattern).'~i', $link[1]))) {
                continue;
            }
            [$from, $to] = preg_match('~class="catalog-date">\s*([^<]+?)\s*<~', $card, $dates) ? self::dates($dates[1]) : [null, null];
            $flyers[$link[1]] ??= new ListedFlyer($link[1], html_entity_decode(strip_tags($link[2]), ENT_QUOTES) ?: null, $from, $to);
        }

        return array_values($flyers);
    }

    /** @return list<string> one catalogue's page pictures, in page order */
    public function pages(ListedFlyer $flyer, SourceFetcher $http): array
    {
        [$response] = $http->get($flyer->url);
        if (! $response->successful()) {
            throw new FetchFailed("The catalogue page answered HTTP {$response->status()}.", $response->status());
        }
        preg_match_all('~https://cdn\.wowdeals\.me/uploads/catalogues/issues/\d+/\d+/1800x1800/(\d+)\.(?:jpe?g|png|webp)~i', $response->body(), $m, PREG_SET_ORDER);
        $pages = [];
        foreach ($m as [$url, $number]) {
            $pages[(int) $number] = $url;
        }
        ksort($pages);

        return array_values($pages);
    }

    /** "23 Oct - 5 Dec" (no year) → ["2026-10-23", "2026-12-05"], taking the year that puts the start nearest today. */
    private static function dates(string $text): array
    {
        if (! preg_match('~^(\d{1,2} [A-Za-z]{3})\w*\s*-\s*(\d{1,2} [A-Za-z]{3})~', trim($text), $m)) {
            return [null, null];
        }
        $today = now(config('app.market_timezone'))->startOfDay();
        $from = Carbon::createFromFormat('j M Y', "{$m[1]} {$today->year}", config('app.market_timezone'))->startOfDay();
        if ($from->diffInDays($today, false) < -180) {
            $from->subYear();
        } elseif ($from->diffInDays($today, false) > 180) {
            $from->addYear();
        }
        $to = Carbon::createFromFormat('j M Y', "{$m[2]} {$from->year}", config('app.market_timezone'))->startOfDay();
        if ($to->lt($from)) {
            $to->addYear();
        }

        return [$from->toDateString(), $to->toDateString()];
    }
}
