<?php

namespace App\Domain\Flyers\Sources;

use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetches retailer pages and flyers politely and safely: says who it is (USER_AGENT), follows robots.txt,
 * goes only to public web addresses (no private, loopback or reserved IPs, checked again on every redirect,
 * since the server runs Typesense and the database on localhost), and refuses files over the size limit.
 */
final class SourceFetcher
{
    public const string USER_AGENT = 'LazyOfferFlyerBot/1.0 (+https://lazyoffer.com)';

    private const int MAX_REDIRECTS = 3;

    /** @return array{0: Response, 1: string} the final response and its address */
    public function get(string $url, array $headers = []): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertPublic($url);
            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, ...$headers])
                    ->timeout(60)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url);
            } catch (ConnectionException $e) {
                throw new FetchFailed('Could not connect: '.$e->getMessage());
            }
            if ($response->redirect() && $response->header('Location')) {
                $url = self::absolute($url, $response->header('Location')) ?? throw new FetchFailed('Bad redirect.');

                continue;
            }
            if (strlen($response->body()) > config('flyers.max_file_mb') * 1024 * 1024) {
                throw new FetchFailed('The file is larger than '.config('flyers.max_file_mb').' MB.');
            }

            return [$response, $url];
        }

        throw new FetchFailed('Too many redirects.');
    }

    /** Only http(s) to public addresses. @throws FetchFailed */
    public function assertPublic(string $url): void
    {
        $parts = parse_url($url);
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || blank($parts['host'] ?? null)) {
            throw new FetchFailed('Only http and https addresses can be fetched.');
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : (config('flyers.resolve_hosts', true) ? (gethostbynamel($host) ?: throw new FetchFailed("Unknown host {$host}.")) : []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new FetchFailed("{$host} is not a public address.");
            }
        }
    }

    /** Does the site's robots.txt let bots fetch this address? Read once a day per site. */
    public function allowedByRobots(string $url): bool
    {
        $parts = parse_url($url);
        $origin = strtolower(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ":{$parts['port']}" : ''));
        $rules = Cache::remember('flyer-robots:'.md5($origin), now()->addDay(), function () use ($origin) {
            try {
                [$response] = $this->get("{$origin}/robots.txt");

                return $response->successful() ? self::robotRules($response->body()) : [];
            } catch (FetchFailed) {
                return []; // no robots.txt: nothing asked
            }
        });
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? "?{$parts['query']}" : '');
        $longest = fn (string $type) => collect($rules[$type] ?? [])->filter(fn (string $rule) => $rule !== '' && str_starts_with($path, $rule))->map(fn ($rule) => strlen($rule))->max() ?? -1;

        return $longest('disallow') <= $longest('allow');
    }

    /** @return array{allow: list<string>, disallow: list<string>} the rules for every bot ("*") and for us */
    public static function robotRules(string $text): array
    {
        $rules = ['allow' => [], 'disallow' => []];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if (! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                $agents = $inRules ? [] : $agents;
                $agents[] = strtolower($value);
                $inRules = false;
            } elseif (in_array($field, ['allow', 'disallow'], true)) {
                $inRules = true;
                if (array_intersect($agents, ['*', 'lazyofferflyerbot'])) {
                    $rules[$field][] = $value;
                }
            }
        }

        return $rules;
    }

    /**
     * Flyer links on a page: anchors whose absolute address matches the pattern (case-insensitive), in page order.
     *
     * @return list<string>
     */
    public static function links(string $html, string $pageUrl, string $pattern): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $links = [];
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $url = self::absolute($pageUrl, trim($anchor->getAttribute('href')));
            if ($url !== null && @preg_match('~'.str_replace('~', '\~', $pattern).'~i', $url)) {
                $links[] = $url;
            }
        }

        return array_values(array_unique($links));
    }

    /** An href (absolute, //host, /path or relative) on $base as an absolute http(s) address, without #fragment. */
    public static function absolute(string $base, string $href): ?string
    {
        $href = preg_replace('/#.*$/', '', $href);
        if ($href === '' || preg_match('/^(mailto|javascript|tel|data):/i', $href)) {
            return null;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ":{$parts['port']}" : '');
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $directory = preg_replace('~/[^/]*$~', '/', $parts['path'] ?? '/');

        return $origin.$directory.$href;
    }
}
