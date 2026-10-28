<?php

namespace App\Domain\Alerts;

use App\Domain\Engagement\Like;
use App\Domain\Offers\Offer;
use App\Models\User;
use App\Notifications\WeeklyDigestMail;

/** Sunday email: this week's live offers on the products each shopper liked (top 10 by discount). */
final class WeeklyDigest
{
    /** @return int emails sent */
    public function send(): int
    {
        $sent = 0;

        Like::query()->where('likeable_type', 'product')->distinct()->pluck('user_id')
            ->chunk(200)
            ->each(function ($userIds) use (&$sent) {
                foreach (User::whereKey($userIds)->get() as $user) {
                    $productIds = Like::where(['user_id' => $user->id, 'likeable_type' => 'product'])->pluck('likeable_id');
                    $offers = Offer::query()->live()
                        ->whereHas('variant', fn ($q) => $q->whereIn('product_id', $productIds))
                        ->with(['chain', 'variant.product.productGroup', 'variant.optionValues'])
                        ->orderByDesc('discount_pct')
                        ->limit(10)
                        ->get();

                    if ($offers->isNotEmpty()) {
                        $user->notify(new WeeklyDigestMail($offers));
                        $sent++;
                    }
                }
            });

        return $sent;
    }
}
