<?php

namespace App\Domain\Offers;

use App\Domain\Alerts\MatchAlerts;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Search\OfferDocument;
use Illuminate\Support\Carbon;

/**
 * Runs just after midnight in the market's timezone: offers and brochures whose last day has passed
 * become expired and leave Typesense; scheduled brochures that start today go live, and offers that
 * start today join Typesense.
 */
final class ExpireOffers
{
    /** Offers starting this many days ago are indexed again, so a missed run catches up. */
    private const int CATCH_UP_DAYS = 3;

    /** @return array{offers: int, brochures: int, started: int} */
    public function __invoke(): array
    {
        $now = Carbon::now(config('app.market_timezone'));
        $today = $now->toDateString();

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
            ->pluck('id');
        Brochure::whereKey($started)->update(['status' => BrochureStatus::Published]);

        // Publishing keeps future offers out of search, and an offer can start after its brochure does.
        Offer::syncSearch(Offer::query()->live($today)
            ->whereDate('valid_from', '>=', $now->copy()->subDays(self::CATCH_UP_DAYS)->toDateString())
            ->with(OfferDocument::RELATIONS)
            ->get());

        // Alerts match live offers only, so people hear about a scheduled brochure when it starts.
        $started->each(fn (int $brochureId) => MatchAlerts::dispatch($brochureId));

        return ['offers' => $expired->count(), 'brochures' => $brochures, 'started' => $started->count()];
    }
}
