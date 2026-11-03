<?php

namespace App\Domain\Offers;

use App\Domain\Search\OfferDocument;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs every minute: timed offers (a daily window such as 08:00–11:00) join search when their window
 * opens and leave it when it closes. Windows that opened or closed in the last few minutes are synced,
 * so a missed run catches up; syncing twice changes nothing.
 */
final class SyncTimedOffers
{
    private const int LOOKBACK_MINUTES = 10;

    /** @return int offers synced */
    public function __invoke(): int
    {
        $now = now(config('app.market_timezone'));
        $since = $now->copy()->subMinutes(self::LOOKBACK_MINUTES);
        // A lookback that crosses midnight starts at 00:00; ExpireOffers handles the day change.
        $from = $since->isSameDay($now) ? $since->format('H:i:s') : '00:00:00';
        $to = $now->format('H:i:s');

        $offers = Offer::query()
            ->live($now->toDateString())
            ->whereNotNull('start_time')
            ->where(fn (Builder $q) => $q
                ->whereBetween('start_time', [$from, $to])
                ->orWhereBetween('end_time', [$from, $to]))
            ->with(OfferDocument::RELATIONS)
            ->get();

        Offer::syncSearch($offers);

        return $offers->count();
    }
}
