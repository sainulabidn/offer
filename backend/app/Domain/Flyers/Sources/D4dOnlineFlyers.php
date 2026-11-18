<?php

namespace App\Domain\Flyers\Sources;

use Illuminate\Support\Carbon;

/**
 * A store on the third-party flyer site D4D Online, as page pictures. The source address is the store's page
 * in one city, e.g. https://d4donline.com/en/uae/dubai/offers/mark-save-3573; its other UAE cities are listed
 * too, so one source covers the whole store. The link pattern can keep some flyers ("al-warsan|muhaisnah").
 *
 * Each flyer page carries a schema.org CreativeWork with the page pictures in order, datePublished (taken as
 * the first day) and expires (the last day, shown as "Till 30th November").
 */
final class D4dOnlineFlyers
{
    /** D4D's city pages in the UAE. */
    public const array UAE_CITIES = ['dubai', 'abu-dhabi', 'sharjah', 'ajman', 'al-ain', 'ras-al-khaimah', 'fujairah', 'umm-al-quwain'];

    /** @var array<string, list<string>> flyer address => page pictures, read while listing */
    private array $pages = [];

    /** @return list<ListedFlyer> the store's flyers that haven't expired, in every UAE city */
    public function __invoke(BrochureSource $source, SourceFetcher $http): array
    {
        if (! preg_match('~^https://(?:www\.)?d4donline\.com/en/uae/[a-z-]+/offers/([a-z0-9-]+-\d+)/?$~', $source->url, $m)) {
            throw new FetchFailed('The address must be a D4D store page, e.g. https://d4donline.com/en/uae/dubai/offers/mark-save-3573.');
        }
        $store = $m[1];
        $links = [];
        $reached = false;
        foreach (self::UAE_CITIES as $city) {
            [$response] = $http->get("https://d4donline.com/en/uae/{$city}/offers/{$store}");
            if (! $response->successful()) {
                continue;
            }
            $reached = true;
            preg_match_all('~https://d4donline\.com/en/uae/[a-z-]+/offers/'.preg_quote($store, '~').'/(\d+)/[a-z0-9-]+~', $response->body(), $found);
            foreach ($found[0] as $i => $url) {
                $links[$found[1][$i]] ??= $url; // one flyer can be listed in several cities
            }
        }
        if (! $reached) {
            throw new FetchFailed('The D4D store page could not be read.');
        }

        $today = now(config('app.market_timezone'))->toDateString();
        $flyers = [];
        foreach ($links as $url) {
            if ($source->link_pattern && ! @preg_match('~'.str_replace('~', '\~', $source->link_pattern).'~i', $url)) {
                continue;
            }
            $flyer = $this->read($url, $http);
            if ($flyer && ($flyer->validTo === null || $flyer->validTo >= $today)) {
                $flyers[] = $flyer;
            }
        }

        return $flyers;
    }

    /** @return list<string> one flyer's page pictures, in page order */
    public function pages(ListedFlyer $flyer, SourceFetcher $http): array
    {
        if (! isset($this->pages[$flyer->url])) {
            $this->read($flyer->url, $http);
        }

        return $this->pages[$flyer->url] ?? [];
    }

    private function read(string $url, SourceFetcher $http): ?ListedFlyer
    {
        [$response] = $http->get($url);
        if (! $response->successful()) {
            return null;
        }
        $work = self::creativeWork($response->body());
        if ($work === null || empty($work['image'])) {
            return null;
        }
        $this->pages[$url] = array_values(array_filter((array) $work['image'], 'is_string'));
        $day = fn (?string $iso) => $iso ? Carbon::parse($iso)->setTimezone(config('app.market_timezone'))->toDateString() : null;

        return new ListedFlyer(
            $url,
            trim(html_entity_decode((string) ($work['author']['name'] ?? ''), ENT_QUOTES).' - '.html_entity_decode((string) ($work['name'] ?? ''), ENT_QUOTES), ' -') ?: null,
            $day($work['datePublished'] ?? null),
            $day($work['expires'] ?? null),
        );
    }

    /** The page's schema.org CreativeWork (JSON-LD), or null. */
    public static function creativeWork(string $html): ?array
    {
        preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~s', $html, $scripts);
        foreach ($scripts[1] as $json) {
            $data = json_decode(trim($json), true);
            foreach (isset($data['@type']) ? [$data] : (array) $data as $item) {
                if (is_array($item) && ($item['@type'] ?? null) === 'CreativeWork') {
                    return $item;
                }
            }
        }

        return null;
    }
}
