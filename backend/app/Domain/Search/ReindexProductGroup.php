<?php

namespace App\Domain\Search;

use App\Domain\Offers\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Queued when a group changes how it is compared: its offers move to new cards, then get matching ranks. */
class ReindexProductGroup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $groupId) {}

    public function handle(RankSync $rankSync): void
    {
        Offer::query()->live()
            ->whereHas('variant.product', fn ($query) => $query->where('product_group_id', $this->groupId))
            ->with(OfferDocument::RELATIONS)
            ->chunkById(500, fn ($offers) => Offer::syncSearch($offers));

        $rankSync();
    }
}
