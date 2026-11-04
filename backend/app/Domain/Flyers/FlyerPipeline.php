<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\BrochurePage;
use App\Domain\Brochures\PageStatus;
use App\Domain\Flyers\Ai\PageResult;

/**
 * Decides what happens to a rendered page, cheapest first: reuse the answer for an identical page read
 * before (free), skip a text page without prices (free), hold it while the monthly budget is spent, or
 * queue it for the AI (ExtractFlyerPage).
 */
final class FlyerPipeline
{
    public static function route(BrochurePage $page): void
    {
        $same = $page->content_hash ? BrochurePage::query()
            ->where('content_hash', $page->content_hash)
            ->whereKeyNot($page->id)
            ->whereNotNull('ai_output')
            ->latest('extracted_at')
            ->first() : null;

        if ($same) {
            $page->update(['ai_output' => $same->ai_output, 'ai_status' => PageStatus::Reused, 'reused_from_page_id' => $same->id, 'extracted_at' => now(), 'ai_error' => null]);
            app(ApplyPageResult::class)($page, PageResult::fromStored($same->ai_output));

            return;
        }
        if (FlyerSettings::get('skip_no_price_pages') && $page->text() !== null && ! preg_match('/\d+\s*[.,]\s*\d{2}\b/', $page->text())) {
            $page->update(['ai_status' => PageStatus::Skipped, 'page_type' => 'info']);

            return;
        }

        self::queue($page);
    }

    /**
     * Sends the page to the AI: now, or with the next half-price batch for economy flyers (FlyerBatches);
     * or holds it while the monthly budget is spent.
     */
    public static function queue(BrochurePage $page): void
    {
        if (! FlyerBudget::allowsMore()) {
            $page->update(['ai_status' => PageStatus::Held]);

            return;
        }
        if ($page->loadMissing('brochure')->brochure->ai_mode === 'economy') {
            $page->update(['ai_status' => PageStatus::Batched, 'ai_batch_id' => null, 'ai_error' => null]);

            return;
        }
        $page->update(['ai_status' => PageStatus::Queued, 'ai_error' => null]);
        ExtractFlyerPage::dispatch($page->id);
    }

    /** Hands the brochure to FinishFlyer once none of its pages is still on its way. */
    public static function finishIfDone(int $brochureId): void
    {
        $open = BrochurePage::query()
            ->where('brochure_id', $brochureId)
            ->whereIn('ai_status', PageStatus::OPEN)
            ->exists();
        if (! $open) {
            FinishFlyer::dispatch($brochureId);
        }
    }
}
