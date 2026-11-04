<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureItem;
use App\Domain\Brochures\BrochurePage;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Flyers\Ai\FlyerReader;
use App\Domain\Flyers\Ai\FlyerReadFailed;
use App\Domain\Flyers\Ai\PageInput;
use App\Domain\Flyers\Ai\PageResult;
use Illuminate\Support\Facades\Storage;

/**
 * How well a model reads flyers we already checked (flyers:eval): the pages of reviewed brochures are read
 * again and compared with the items reviewers accepted — found, price, was price and product — with the
 * cost per page. The way to choose a cheaper model on evidence. Calls are logged (purpose "eval").
 */
final class EvaluateFlyerModel
{
    public function __construct(private readonly VariantMatcher $matcher) {}

    /**
     * @param  list<int>  $brochureIds
     * @return array{pages: int, expected: int, found: int, price: int, was: int, product: int, cost: float, failed: int}
     */
    public function __invoke(array $brochureIds, FlyerReader $reader): array
    {
        $score = ['pages' => 0, 'expected' => 0, 'found' => 0, 'price' => 0, 'was' => 0, 'product' => 0, 'cost' => 0.0, 'failed' => 0];
        $pages = BrochurePage::query()
            ->whereIn('brochure_id', $brochureIds)
            ->whereHas('items', fn ($q) => $q->where('status', ItemStatus::Accepted))
            ->with(['brochure.chain', 'items' => fn ($q) => $q->where('status', ItemStatus::Accepted)])
            ->get();

        foreach ($pages as $page) {
            $score['pages']++;
            try {
                $result = $reader->extract(new PageInput(
                    (string) Storage::disk(config('flyers.disk'))->get($page->image_path),
                    (int) $page->width,
                    (int) $page->height,
                    (string) $page->brochure->chain->name,
                    now(config('app.market_timezone'))->toDateString(),
                    $page->text(),
                ));
            } catch (FlyerReadFailed $e) {
                $score['failed']++;
                $score['expected'] += $page->items->count();
                $this->log($reader, $page, $e->usage ? CostCalculator::cost($e->model ?? $reader->model(), $e->usage) : 0, $e->reason);

                continue;
            }
            $cost = (float) CostCalculator::cost($result->model, $result->usage);
            $score['cost'] += $cost;
            $this->log($reader, $page, $cost, 'ok');
            $this->compare($page, $page->brochure, $result, $score);
        }

        return $score;
    }

    private function compare(BrochurePage $page, Brochure $brochure, PageResult $result, array &$score): void
    {
        $predicted = array_map(function (array $raw) use ($result, $brochure) {
            $item = ItemNormalizer::normalize($raw, $result);

            return $item + $this->matcher->match($item, $brochure->chain_id, $raw['barcode'] ?? null);
        }, $result->items);

        foreach ($page->items as $truth) {
            $score['expected']++;
            $match = $this->pair($truth, $predicted);
            if ($match === null) {
                continue;
            }
            $score['found']++;
            $score['price'] += abs((float) $match['price'] - (float) $truth->price) < 0.005 ? 1 : 0;
            $score['was'] += ($match['regular_price'] === null && $truth->regular_price === null)
                || ($match['regular_price'] !== null && $truth->regular_price !== null && abs((float) $match['regular_price'] - (float) $truth->regular_price) < 0.005) ? 1 : 0;
            $score['product'] += $match['product_variant_id'] === $truth->product_variant_id ? 1 : 0;
        }
    }

    /** The predicted item for an accepted one: same product, else the most similar name (70 %+). */
    private function pair(BrochureItem $truth, array $predicted): ?array
    {
        foreach ($predicted as $item) {
            if ($item['product_variant_id'] !== null && $item['product_variant_id'] === $truth->product_variant_id) {
                return $item;
            }
        }
        $best = null;
        $bestScore = 70.0;
        foreach ($predicted as $item) {
            similar_text(FlyerAlias::key($truth->name), FlyerAlias::key($item['name']), $percent);
            if ($percent >= $bestScore) {
                [$best, $bestScore] = [$item, $percent];
            }
        }

        return $best;
    }

    private function log(FlyerReader $reader, BrochurePage $page, ?float $cost, string $status): void
    {
        AiCall::create([
            'provider' => $reader->provider(),
            'model' => $reader->model(),
            'purpose' => 'eval',
            'brochure_id' => $page->brochure_id,
            'brochure_page_id' => $page->id,
            'cost_usd' => $cost ?? 0,
            'status' => $status,
        ]);
    }
}
