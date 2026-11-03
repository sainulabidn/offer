<?php

namespace App\Domain\Alerts;

use App\Domain\Brochures\Brochure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued right after a brochure is published, so publishing stays fast for the operator. One per brochure
 * at a time: offers added one by one (DirectOffers, a CSV) are queued with a delay and share one run.
 */
class MatchAlerts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(public int $brochureId) {}

    public function uniqueId(): string
    {
        return (string) $this->brochureId;
    }

    public function handle(AlertMatcher $matcher): void
    {
        if ($brochure = Brochure::find($this->brochureId)) {
            $matcher->forBrochure($brochure);
        }
    }
}
