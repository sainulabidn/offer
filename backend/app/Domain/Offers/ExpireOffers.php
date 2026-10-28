<?php

namespace App\Domain\Offers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use Illuminate\Support\Carbon;

/**
 * Runs just after midnight in the market's timezone: offers and brochures whose last day has passed
 * become expired and leave Typesense; scheduled brochures that start today go live.
 */
final class ExpireOffers
{
    /** @return array{offers: int, brochures: int, started: int} */
    public function __invoke(): array
    {
        $today = Carbon::now(config('app.market_timezone'))->toDateString();

        $expired = Offer::query()
            ->where('status', OfferStatus::Published)
            ->whereDate('valid_to', '<', $today)
            ->get();
        $expired->unsearchable();
        Offer::whereKey($expired->modelKeys())->update(['status' => OfferStatus::Expired]);

        $brochures = Brochure::query()
            ->whereIn('status', [BrochureStatus::Published, BrochureStatus::Scheduled])
            ->whereDate('valid_to', '<', $today)
            ->update(['status' => BrochureStatus::Expired]);

        $started = Brochure::query()
            ->where('status', BrochureStatus::Scheduled)
            ->whereDate('valid_from', '<=', $today)
            ->update(['status' => BrochureStatus::Published]);

        return ['offers' => $expired->count(), 'brochures' => $brochures, 'started' => $started];
    }
}
