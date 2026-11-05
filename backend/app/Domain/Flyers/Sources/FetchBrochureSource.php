<?php

namespace App\Domain\Flyers\Sources;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Flyers\FlyerSettings;
use App\Domain\Flyers\StartFlyerImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checks one source for a new flyer and imports it (StartFlyerImport, as the system). Cheap when nothing
 * changed: the server answers 304 to our ETag/Last-Modified, a listed flyer was fetched before (no download),
 * or the file's hash is one we have. Failures
 * back off — 1, 2, 4… times the check interval, up to a day — and settings editors hear once after three
 * in a row, and once when it works again. Stores without permission are never fetched.
 */
class FetchBrochureSource implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Alert after this many failed checks in a row. */
    public const int ALERT_AFTER = 3;

    /** New links taken from a page per check (the newest flyers are usually listed first). */
    private const int LINKS_PER_CHECK = 5;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $sourceId)
    {
        $this->onConnection(config('flyers.queue_connection'));
        $this->onQueue('fetch');
    }

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(SourceFetcher $http, StartFlyerImport $start): void
    {
        $source = BrochureSource::with('chain')->find($this->sourceId);
        if (! $source || ! $source->is_active) {
            return;
        }
        if (! $source->permitted()) {
            $this->record($source, $source->url, 'blocked', error: "{$source->chain->name} has not given permission to fetch its flyers.");
            $source->update(['last_checked_at' => now(), 'next_check_at' => now()->addDay()]);

            return;
        }

        try {
            $flyers = $source->kind === BrochureSource::KIND_PDF ? [new ListedFlyer($source->url)] : $this->unseen($source, $this->listed($source, $http), $http);
            if ($flyers === []) {
                $this->record($source, $source->url, 'unchanged');
            }
            foreach ($flyers as $flyer) {
                $this->download($source, $flyer, $http, $start);
            }
            $this->succeeded($source);
        } catch (FetchFailed $e) {
            $this->record($source, $source->url, 'failed', $e->status, error: $e->getMessage());
            $this->failedCheck($source, $e->getMessage());
        }
    }

    /** @return list<ListedFlyer> every flyer the page or the store's offer site lists now */
    private function listed(BrochureSource $source, SourceFetcher $http): array
    {
        $this->allow($source->url, $http);
        if ($source->kind === BrochureSource::KIND_NESTO) {
            return (new NestoOffers)($source, $http);
        }
        [$response, $final] = $http->get($source->url);
        if (! $response->successful()) {
            throw new FetchFailed("The page answered HTTP {$response->status()}.", $response->status());
        }
        $links = SourceFetcher::links($response->body(), $final, $source->link_pattern ?: BrochureSource::DEFAULT_PATTERN);
        if ($links === []) {
            throw new FetchFailed('No flyer links found on the page; it may have changed, or show flyers only with JavaScript.');
        }

        return array_map(fn (string $url) => new ListedFlyer($url), $links);
    }

    /**
     * The listed flyers we haven't fetched before, a few per check; known ones cost no download.
     *
     * @param  list<ListedFlyer>  $flyers
     * @return list<ListedFlyer>
     */
    private function unseen(BrochureSource $source, array $flyers, SourceFetcher $http): array
    {
        $seen = array_flip($source->fetches()->whereIn('outcome', ['new', 'duplicate', 'unchanged', 'gone'])->pluck('url')->all());
        $fresh = [];
        foreach ($flyers as $flyer) {
            // Links the site asks bots to leave alone are skipped, not failed: the page itself was allowed.
            if (! isset($seen[$flyer->url]) && ! isset($fresh[$flyer->url]) && $http->allowedByRobots($flyer->url)) {
                $fresh[$flyer->url] = $flyer;
            }
        }

        return array_slice(array_values($fresh), 0, self::LINKS_PER_CHECK);
    }

    private function download(BrochureSource $source, ListedFlyer $flyer, SourceFetcher $http, StartFlyerImport $start): void
    {
        $url = $flyer->url;
        $this->allow($url, $http);
        $started = hrtime(true);
        $conditional = $url === $source->url ? array_filter(['If-None-Match' => $source->etag, 'If-Modified-Since' => $source->last_modified]) : [];
        [$response, $final] = $http->get($url, $conditional);
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($response->status() === 304) {
            $this->record($source, $url, 'unchanged', 304, ms: $ms);

            return;
        }
        // Pages often keep linking old flyers they have deleted: skip those for good rather than fail every check.
        if ($url !== $source->url && in_array($response->status(), [404, 410], true)) {
            $this->record($source, $url, 'gone', $response->status(), ms: $ms);

            return;
        }
        if (! $response->successful()) {
            throw new FetchFailed("The flyer link answered HTTP {$response->status()}.", $response->status());
        }
        $bytes = $response->body();
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new FetchFailed('The link did not return a PDF.', $response->status());
        }
        $hash = hash('sha256', $bytes);
        $validators = $url === $source->url ? ['etag' => $response->header('ETag') ?: null, 'last_modified' => $response->header('Last-Modified') ?: null] : [];
        $known = Brochure::query()->where('chain_id', $source->chain_id)->where('file_hash', $hash)
            ->whereNotIn('status', [BrochureStatus::Rejected, BrochureStatus::Archived])->first();

        if ($hash === $source->last_hash || $known) {
            $this->record($source, $url, $known ? 'duplicate' : 'unchanged', $response->status(), strlen($bytes), $hash, $known?->id, ms: $ms);
            $source->update($validators);

            return;
        }

        $path = config('flyers.directory').'/incoming/'.Str::random(20).'.pdf';
        Storage::disk(config('flyers.disk'))->put($path, $bytes);
        try {
            $brochure = $start(null, $source->chain, [$path], array_map('intval', $source->branch_ids ?? []), [
                'title' => $flyer->title ?? "{$source->name} ".now(config('app.market_timezone'))->format('j M Y'),
                // Dates the store gives are kept as they are (FinishFlyer), so a flyer for next week waits until then.
                'valid_from' => $flyer->validFrom,
                'valid_to' => $flyer->validTo,
                'source' => 'auto_fetch',
                'source_url' => $final,
                // Nobody is waiting for it: half price through the batch API (FlyerBatches).
                'mode' => FlyerSettings::get('batch_for_sources') ? 'economy' : 'fast',
            ]);
        } catch (ValidationException $e) {
            Storage::disk(config('flyers.disk'))->delete($path);
            throw new FetchFailed('Downloaded, but not imported: '.collect($e->errors())->flatten()->first());
        }
        $brochure->forceFill(['brochure_source_id' => $source->id])->save();
        $this->record($source, $url, 'new', $response->status(), strlen($bytes), $hash, $brochure->id, ms: $ms);
        $source->update([...$validators, 'last_hash' => $url === $source->url ? $hash : $source->last_hash, 'last_new_at' => now(), 'stale_alerted_at' => null]);
    }

    private function allow(string $url, SourceFetcher $http): void
    {
        if (! $http->allowedByRobots($url)) {
            throw new FetchFailed('The site\'s robots.txt asks bots not to fetch this address. Ask the store for permission and a flyer link we may use.');
        }
    }

    private function succeeded(BrochureSource $source): void
    {
        if ($source->failure_alerted_at) {
            SourceAlerts::send($source, "Flyer source working again: {$source->chain->name}", $source->name);
        }
        $source->update([
            'consecutive_failures' => 0,
            'last_error' => null,
            'failure_alerted_at' => null,
            'last_checked_at' => now(),
            'next_check_at' => self::next($source->check_minutes),
        ]);
    }

    private function failedCheck(BrochureSource $source, string $error): void
    {
        $failures = $source->consecutive_failures + 1;
        $alert = $failures >= self::ALERT_AFTER && $source->failure_alerted_at === null;
        $source->update([
            'consecutive_failures' => $failures,
            'last_error' => $error,
            'last_checked_at' => now(),
            'next_check_at' => self::next(min($source->check_minutes * 2 ** ($failures - 1), 24 * 60)),
            'failure_alerted_at' => $alert ? now() : $source->failure_alerted_at,
        ]);
        if ($alert) {
            SourceAlerts::send($source, "Flyer source failing: {$source->chain->name}", "{$source->name}: {$error} ({$failures} checks in a row). Upload this week's flyer by hand meanwhile.");
        }
    }

    /** About $minutes from now, ±10 %, so sources don't all check in the same minute. */
    private static function next(int $minutes)
    {
        return now()->addSeconds((int) round($minutes * 60 * (0.9 + mt_rand() / mt_getrandmax() * 0.2)));
    }

    private function record(BrochureSource $source, string $url, string $outcome, ?int $status = null, ?int $bytes = null, ?string $hash = null, ?int $brochureId = null, ?string $error = null, ?int $ms = null): void
    {
        $source->fetches()->create([
            'url' => $url, 'outcome' => $outcome, 'http_status' => $status, 'bytes' => $bytes, 'sha256' => $hash,
            'brochure_id' => $brochureId, 'error' => $error, 'duration_ms' => $ms,
        ]);
    }
}
