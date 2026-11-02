<?php

namespace App\Domain\Search;

use App\Domain\Offers\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Queued when a store changes something offers copy into Typesense: a moved branch, a new grade… */
class ReindexBrochures implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @param  list<int>  $brochureIds */
    public function __construct(public array $brochureIds) {}

    public function handle(): void
    {
        Offer::query()->live()
            ->whereIn('brochure_id', $this->brochureIds)
            ->with(OfferDocument::RELATIONS)
            ->chunkById(500, fn ($offers) => Offer::syncSearch($offers));
    }
}
