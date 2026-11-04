<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\PageStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Makes a PDF flyer's page rows and renders them a few at a time (RenderFlyerPages), within the render queue's minute. */
class PrepareFlyer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Pages per render job: keeps each job well under the 60 s queue timeout. */
    public const int PAGES_PER_JOB = 6;

    public int $tries = 3;

    public int $timeout = 55;

    public function __construct(public int $brochureId)
    {
        $this->onQueue('render');
    }

    public function handle(): void
    {
        $brochure = Brochure::find($this->brochureId);
        if (! $brochure) {
            return;
        }

        if ($brochure->type === 'pdf') {
            for ($no = 1; $no <= $brochure->page_count; $no++) {
                $brochure->pages()->firstOrCreate(['page_no' => $no], ['ai_status' => PageStatus::Pending]);
            }
        }

        $pending = $brochure->pages()->where('ai_status', PageStatus::Pending)->pluck('page_no');
        foreach ($pending->chunk(self::PAGES_PER_JOB) as $chunk) {
            RenderFlyerPages::dispatch($brochure->id, $chunk->values()->all());
        }
        if ($pending->isEmpty()) {
            FlyerPipeline::finishIfDone($brochure->id);
        }
    }
}
