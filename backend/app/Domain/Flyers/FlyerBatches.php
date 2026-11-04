<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\BrochurePage;
use App\Domain\Brochures\PageStatus;
use App\Domain\Flyers\Ai\BatchFlyerReader;
use App\Domain\Flyers\Ai\FlyerReaderManager;
use App\Domain\Flyers\Ai\FlyerReadFailed;
use App\Domain\Flyers\Ai\PageResult;
use App\Domain\Flyers\Ai\TokenUsage;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Economy flyers (automatic sources, or uploads marked economy) are read through the provider's batch API
 * at half price (flyers:batches, every 5 minutes): pages waiting in a batch are sent together, and finished
 * batches are collected — answers turned into items as for any page. Pages a batch couldn't answer go the
 * normal way (ExtractFlyerPage), so nothing waits more than a day.
 */
final class FlyerBatches
{
    /** Pages per batch: page pictures make requests large (providers allow ~200 MB per batch). */
    private const int MAX_PAGES = 100;

    /** A provider gives up on a batch after 24 hours; past this we stop asking and read its pages one by one. */
    private const int GIVE_UP_HOURS = 26;

    public function __construct(private readonly FlyerReaderManager $readers, private readonly ApplyPageResult $apply) {}

    /** @return int pages sent */
    public function submit(): int
    {
        $pages = BrochurePage::query()->where('ai_status', PageStatus::Batched)->whereNull('ai_batch_id')
            ->with('brochure.chain')->orderBy('id')->limit(self::MAX_PAGES)->get();
        if ($pages->isEmpty() || ! FlyerBudget::allowsMore()) {
            return 0;
        }
        $reader = $this->readers->main();
        if (! $reader instanceof BatchFlyerReader) {
            $this->readNow($pages);

            return 0;
        }

        $inputs = [];
        foreach ($pages as $page) {
            try {
                $inputs["page-{$page->id}"] = ExtractFlyerPage::pageInput($page);
            } catch (Throwable $e) {
                $page->update(['ai_status' => PageStatus::Failed, 'ai_error' => $e->getMessage()]);
            }
        }
        $sent = $pages->filter(fn (BrochurePage $page) => isset($inputs["page-{$page->id}"]));
        $batch = AiBatch::create(['provider' => $reader->provider(), 'model' => $reader->model(), 'page_count' => $sent->count()]);
        try {
            $batch->update(['external_id' => $reader->submitBatch($inputs)]);
        } catch (FlyerReadFailed $e) {
            $batch->update(['status' => 'failed', 'error' => $e->getMessage(), 'ended_at' => now()]);
            if (! $e->retryable) {
                $this->readNow($sent); // the batch API won't take them; the normal way might
            }

            return 0;
        }
        BrochurePage::query()->whereKey($sent->modelKeys())->update(['ai_batch_id' => $batch->id]);

        return $sent->count();
    }

    /** @return int pages answered */
    public function collect(): int
    {
        $answered = 0;
        foreach (AiBatch::query()->where('status', 'submitted')->whereNotNull('external_id')->get() as $batch) {
            $reader = $this->readers->make($batch->provider, $batch->model, (string) FlyerSettings::get('effort'));
            if (! $reader instanceof BatchFlyerReader) {
                continue;
            }
            $pages = $batch->pages()->where('ai_status', PageStatus::Batched)->with('brochure.chain')->get()->keyBy(fn (BrochurePage $page) => "page-{$page->id}");
            try {
                if (! $reader->batchEnded($batch->external_id)) {
                    if ($batch->created_at->isBefore(now()->subHours(self::GIVE_UP_HOURS))) {
                        $this->giveUp($batch, $pages, 'The batch took longer than a day.');
                    }

                    continue;
                }
                foreach ($reader->batchResults($batch->external_id) as $customId => $result) {
                    if ($page = $pages->pull($customId)) {
                        $answered += $this->apply($reader, $page, $result) ? 1 : 0;
                    }
                }
            } catch (FlyerReadFailed $e) {
                $batch->update(['error' => $e->getMessage()]);

                continue; // asked again in 5 minutes
            }
            $this->giveUp($batch, $pages, null); // pages the batch didn't answer
        }

        return $answered;
    }

    private function apply(BatchFlyerReader $reader, BrochurePage $page, PageResult|FlyerReadFailed $result): bool
    {
        if ($result instanceof FlyerReadFailed) {
            AiCall::record($reader, $page, $result->model ?? $reader->model(), $result->usage ?? new TokenUsage, $result->reason, $result->getMessage(), $result->requestId, mode: 'batch');
            $page->update(['ai_status' => PageStatus::Queued, 'ai_error' => $result->getMessage()]);
            ExtractFlyerPage::dispatch($page->id, tiles: $result->reason === FlyerReadFailed::TRUNCATED);

            return false;
        }
        AiCall::record($reader, $page, $result->model, $result->usage, requestId: $result->requestId, mode: 'batch');
        $page->update(['ai_output' => $result->toArray(), 'ai_status' => PageStatus::Done, 'extracted_at' => now(), 'ai_error' => null]);
        ($this->apply)($page, $result);
        FlyerPipeline::finishIfDone($page->brochure_id);

        return true;
    }

    /** Ends the batch; pages still waiting in it are read the normal way. */
    private function giveUp(AiBatch $batch, Collection $pages, ?string $error): void
    {
        $batch->update(['status' => $error ? 'failed' : 'ended', 'error' => $error, 'ended_at' => now()]);
        $this->readNow($pages);
    }

    private function readNow(Collection $pages): void
    {
        foreach ($pages as $page) {
            $page->update(['ai_status' => PageStatus::Queued, 'ai_batch_id' => null]);
            ExtractFlyerPage::dispatch($page->id);
        }
    }
}
