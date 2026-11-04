<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\BrochureItem;
use App\Domain\Brochures\BrochurePage;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Flyers\Ai\PageResult;
use Illuminate\Support\Facades\DB;

/**
 * Turns what the AI read off a page into brochure items: normalised (ItemNormalizer), matched to a pack
 * (VariantMatcher) and checked (ItemValidator). No AI and no cost, so "Re-match" runs it again from the
 * stored answer. Items someone already accepted are kept; the rest are replaced.
 */
final class ApplyPageResult
{
    public function __construct(private readonly VariantMatcher $matcher) {}

    /** @return int items on the page waiting for review */
    public function __invoke(BrochurePage $page, PageResult $result): int
    {
        $brochure = $page->loadMissing('brochure')->brochure;

        return DB::transaction(function () use ($page, $result, $brochure) {
            $page->items()->where('status', '!=', ItemStatus::Accepted)->delete();
            $accepted = $page->items()->get(['product_variant_id', 'price'])
                ->map(fn (BrochureItem $item) => "{$item->product_variant_id}:{$item->price}")->all();
            $page->update(['page_type' => $result->pageType]);

            $count = 0;
            foreach ($result->items as $position => $raw) {
                $item = ItemNormalizer::normalize($raw, $result);
                $item += $this->matcher->match($item, $brochure->chain_id, $raw['barcode'] ?? null);
                if (in_array("{$item['product_variant_id']}:{$item['price']}", $accepted, true)) {
                    continue; // already on the brochure as an offer
                }
                $item['issues'] = ItemValidator::issues($item, $brochure->chain_id, $page->text());
                $item['status'] = $item['product_variant_id'] !== null && $item['issues'] === [] ? ItemStatus::Matched : ItemStatus::New;

                $page->items()->create([...$item, 'brochure_id' => $brochure->id, 'position' => $position]);
                $count++;
            }

            return $count;
        });
    }
}
