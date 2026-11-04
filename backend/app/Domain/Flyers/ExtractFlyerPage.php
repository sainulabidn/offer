<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\BrochurePage;
use App\Domain\Brochures\PageStatus;
use App\Domain\Flyers\Ai\FlyerReader;
use App\Domain\Flyers\Ai\FlyerReaderManager;
use App\Domain\Flyers\Ai\FlyerReadFailed;
use App\Domain\Flyers\Ai\PageInput;
use App\Domain\Flyers\Ai\PageResult;
use App\Domain\Flyers\Ai\TokenUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Reads one page with the AI admins chose, logs what it cost (AiCall) and turns the answer into items
 * (ApplyPageResult). The page is claimed first (queued → extracting), so a job delivered twice never pays
 * twice. Busy or unreachable APIs are retried later; other failures go to the fallback model when one is
 * set. An answer cut off at the output limit is read again as two overlapping halves.
 *
 * Runs on config('flyers.queue_connection'), whose retry_after allows the minutes an AI call can take.
 */
class ExtractFlyerPage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Top half 0–55 %, bottom half 45–100 %: products on the fold appear whole in one of them. */
    private const float TILE_SPLIT = 0.55;

    public int $tries = 3;

    public int $timeout = 240;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 900;

    public function __construct(public int $pageId, public bool $tiles = false)
    {
        $this->onConnection(config('flyers.queue_connection'));
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return $this->pageId.($this->tiles ? ':tiles' : '');
    }

    public function handle(FlyerReaderManager $readers): void
    {
        $claimed = BrochurePage::query()->whereKey($this->pageId)->where('ai_status', PageStatus::Queued)
            ->update(['ai_status' => PageStatus::Extracting, 'ai_attempts' => DB::raw('ai_attempts + 1'), 'updated_at' => now()]);
        if ($claimed === 0) {
            return; // done, removed, or another worker has it
        }
        $page = BrochurePage::with('brochure.chain')->findOrFail($this->pageId);
        if (! FlyerBudget::allowsMore()) {
            $page->update(['ai_status' => PageStatus::Held]);

            return;
        }

        try {
            $result = $this->read($readers->main(), $page);
        } catch (FlyerReadFailed $e) {
            if ($e->reason === FlyerReadFailed::TRUNCATED && ! $this->tiles) {
                // Too much on the page for one answer: read it again in halves (its own job, its own time limit).
                $page->update(['ai_status' => PageStatus::Queued, 'ai_error' => $e->getMessage()]);
                self::dispatch($page->id, tiles: true);

                return;
            }
            if ($e->retryable && $this->attempts() < $this->tries) {
                $page->update(['ai_status' => PageStatus::Queued, 'ai_error' => $e->getMessage()]);
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            $result = $this->fallback($readers, $page, $e);
            if ($result === null) {
                return;
            }
        }

        $page->update(['ai_output' => $result->toArray(), 'ai_status' => PageStatus::Done, 'extracted_at' => now(), 'ai_error' => null]);
        app(ApplyPageResult::class)($page, $result);
        FlyerPipeline::finishIfDone($page->brochure_id);
    }

    /** The job itself died (timeout, crash) after its last try. */
    public function failed(Throwable $e): void
    {
        $page = BrochurePage::find($this->pageId);
        if ($page && $page->ai_status === PageStatus::Extracting) {
            $page->update(['ai_status' => PageStatus::Failed, 'ai_error' => 'Reading the page failed: '.$e->getMessage()]);
            FlyerPipeline::finishIfDone($page->brochure_id);
        }
    }

    private function fallback(FlyerReaderManager $readers, BrochurePage $page, FlyerReadFailed $error): ?PageResult
    {
        $fallback = $readers->fallback();
        try {
            if ($fallback === null) {
                throw $error;
            }

            return $this->read($fallback, $page);
        } catch (FlyerReadFailed $last) {
            $page->update(['ai_status' => PageStatus::Failed, 'ai_error' => $last->getMessage()]);
            FlyerPipeline::finishIfDone($page->brochure_id);

            return null;
        }
    }

    private function read(FlyerReader $reader, BrochurePage $page): PageResult
    {
        $image = Storage::disk(config('flyers.disk'))->get($page->image_path) ?? throw new RuntimeException("Missing page render {$page->image_path}");

        if (! $this->tiles && ! FlyerSettings::get('tile_dense_pages')) {
            return $this->call($reader, $page, $this->input($page, $image, $page->width, $page->height));
        }

        // Two overlapping halves; their boxes are put back on the whole page and items on the fold kept once.
        $source = imagecreatefromstring($image) ?: throw new RuntimeException('Unreadable page render.');
        $height = imagesy($source);
        $tileHeight = (int) round($height * self::TILE_SPLIT);
        $results = [];
        foreach ([0, $height - $tileHeight] as $top) {
            $tile = imagecrop($source, ['x' => 0, 'y' => $top, 'width' => imagesx($source), 'height' => $tileHeight]);
            ob_start();
            imagejpeg($tile, null, 85);
            $results[] = [$top, $this->call($reader, $page, $this->input($page, (string) ob_get_clean(), imagesx($source), $tileHeight))];
        }

        return $this->merge($results, $height, $tileHeight);
    }

    private function input(BrochurePage $page, string $image, int $width, int $height): PageInput
    {
        return self::pageInput($page, $image, $width, $height, withText: ! $this->tiles);
    }

    /** What the AI gets for a page (or a tile of it); the whole page from its render when no picture is given. */
    public static function pageInput(BrochurePage $page, ?string $image = null, ?int $width = null, ?int $height = null, bool $withText = true): PageInput
    {
        $image ??= Storage::disk(config('flyers.disk'))->get($page->image_path) ?? throw new RuntimeException("Missing page render {$page->image_path}");

        return new PageInput(
            image: $image,
            width: $width ?? (int) $page->width,
            height: $height ?? (int) $page->height,
            store: (string) $page->loadMissing('brochure.chain')->brochure->chain->name,
            today: now(config('app.market_timezone'))->toDateString(),
            text: $withText ? $page->text() : null,
            arabicNames: (bool) FlyerSettings::get('arabic_names'),
        );
    }

    /** One AI request, logged with its cost whether it worked or not. */
    private function call(FlyerReader $reader, BrochurePage $page, PageInput $input): PageResult
    {
        $started = hrtime(true);
        $log = fn (string $model, TokenUsage $usage, string $status, ?string $error, ?string $requestId) => AiCall::record(
            $reader, $page, $model, $usage, $status, $error, $requestId, ms: (int) ((hrtime(true) - $started) / 1_000_000),
        );

        try {
            $result = $reader->extract($input);
        } catch (FlyerReadFailed $e) {
            $log($e->model ?? $reader->model(), $e->usage ?? new TokenUsage, $e->reason, $e->getMessage(), $e->requestId);
            throw $e;
        }
        $log($result->model, $result->usage, 'ok', null, $result->requestId);

        return $result;
    }

    /** @param  list<array{0: int, 1: PageResult}>  $results  [tile top in pixels, answer] */
    private function merge(array $results, int $height, int $tileHeight): PageResult
    {
        $items = [];
        $usage = new TokenUsage;
        foreach ($results as [$top, $result]) {
            $usage = $usage->plus($result->usage);
            foreach ($result->items as $item) {
                if (is_array($item['box'] ?? null) && count($item['box']) === 4) {
                    // Thousandths of the tile → thousandths of the page.
                    $item['box'][1] = (int) round(($top + $item['box'][1] / 1000 * $tileHeight) / $height * 1000);
                    $item['box'][3] = (int) round(($top + $item['box'][3] / 1000 * $tileHeight) / $height * 1000);
                }
                $twin = collect($items)->search(fn (array $kept) => FlyerAlias::key($kept['name']) === FlyerAlias::key($item['name'])
                    && abs((float) $kept['price'] - (float) $item['price']) < 0.005);
                if ($twin === false) {
                    $items[] = $item;
                }
            }
        }
        [$first, $second] = [$results[0][1], $results[1][1]];

        return new PageResult(
            pageType: $first->items || $second->items ? 'offers' : $first->pageType,
            validFrom: $first->validFrom ?? $second->validFrom,
            validTo: $first->validTo ?? $second->validTo,
            locations: array_values(array_unique([...$first->locations, ...$second->locations])),
            items: $items,
            usage: $usage,
            model: $first->model,
            requestId: $first->requestId,
        );
    }
}
