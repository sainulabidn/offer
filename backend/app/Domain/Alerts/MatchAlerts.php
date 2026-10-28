<?php

namespace App\Domain\Alerts;

use App\Domain\Brochures\Brochure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Queued right after a brochure is published, so publishing stays fast for the operator. */
class MatchAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $brochureId) {}

    public function handle(AlertMatcher $matcher): void
    {
        if ($brochure = Brochure::find($this->brochureId)) {
            $matcher->forBrochure($brochure);
        }
    }
}
