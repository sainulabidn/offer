<?php

namespace App\Domain\Alerts;

use App\Domain\Brochures\Brochure;
use App\Domain\Engagement\Like;
use App\Domain\Offers\Offer;
use App\Models\User;
use App\Notifications\WatchlistOffers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * After a brochure is published: who should hear about which of its offers?
 * Explicit alerts (with price, discount and radius conditions) plus liked products (no conditions).
 * Each person gets one email listing their matches, and never hears about the same offer twice.
 */
final class AlertMatcher
{
    /** @return int how many people were notified */
    public function forBrochure(Brochure $brochure): int
    {
        $offers = Offer::query()->live()->where('brochure_id', $brochure->id)
            ->with(['chain', 'variant.product.productGroup', 'variant.optionValues'])
            ->get();
        if ($offers->isEmpty()) {
            return 0;
        }

        /** @var array<int, Collection<int, Offer>> $matches user id → offers */
        $matches = [];
        $add = function (int $userId, Offer $offer) use (&$matches) {
            $matches[$userId] ??= collect();
            $matches[$userId]->put($offer->id, $offer);
        };

        foreach ($this->matchingAlerts($brochure, $offers) as $row) {
            $add($row->user_id, $offers->firstWhere('id', $row->offer_id));
        }

        $likes = Like::query()->where('likeable_type', 'product')
            ->whereIn('likeable_id', $offers->pluck('variant.product_id')->unique())
            ->get(['user_id', 'likeable_id']);
        foreach ($likes as $like) {
            foreach ($offers->where('variant.product_id', $like->likeable_id) as $offer) {
                $add($like->user_id, $offer);
            }
        }

        $notified = 0;
        foreach ($matches as $userId => $userOffers) {
            $already = DB::table('alert_deliveries')->where('user_id', $userId)->whereIn('offer_id', $userOffers->keys())->pluck('offer_id');
            $fresh = $userOffers->except($already->all())->values();
            if ($fresh->isEmpty() || ! ($user = User::find($userId))) {
                continue;
            }

            DB::table('alert_deliveries')->insertOrIgnore($fresh->map(fn (Offer $o) => ['user_id' => $userId, 'offer_id' => $o->id])->all());
            Alert::where('user_id', $userId)->update(['last_notified_at' => now()]);
            $user->notify(new WatchlistOffers($fresh->sortByDesc('discount_pct')->values()));
            $notified++;
        }

        return $notified;
    }

    /**
     * One SQL query: alert × offer pairs where the target matches, price/discount conditions hold,
     * and (for alerts with a location) a branch carrying this brochure is within the radius.
     */
    private function matchingAlerts(Brochure $brochure, Collection $offers): Collection
    {
        $ids = $offers->pluck('id')->all();

        return collect(DB::select(<<<'SQL'
            SELECT a.user_id, o.id AS offer_id
            FROM alerts a
            JOIN offers o ON o.id = ANY(?::bigint[])
            JOIN product_variants v ON v.id = o.product_variant_id
            JOIN products p ON p.id = v.product_id
            WHERE a.is_active
              AND (
                   (a.target_type = 'variant' AND a.target_id = v.id)
                OR (a.target_type = 'product' AND a.target_id = p.id)
                OR (a.target_type = 'group'   AND a.target_id = p.product_group_id)
              )
              AND (a.max_unit_price IS NULL OR o.effective_unit_price <= a.max_unit_price)
              AND (a.min_discount IS NULL OR o.discount_pct >= a.min_discount)
              AND (a.location IS NULL OR EXISTS (
                    SELECT 1 FROM brochure_branch bb
                    JOIN branches b ON b.id = bb.branch_id
                    WHERE bb.brochure_id = ?
                      AND ST_DWithin(b.location, a.location, COALESCE(a.radius_km, 10) * 1000)
              ))
            SQL, ['{'.implode(',', $ids).'}', $brochure->id]));
    }
}
