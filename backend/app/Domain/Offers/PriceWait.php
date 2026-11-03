<?php

namespace App\Domain\Offers;

use App\Domain\Access\Access;
use App\Domain\Retail\Chain;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Store users can't set a new price for the same pack at the same store until some hours have passed
 * (config offers.price_wait_hours, OFFER_PRICE_WAIT_HOURS). Every offer counts, whether it came from a
 * brochure, manual entry or a CSV, so prices can't be flipped back and forth to climb the rankings.
 * Office staff with the "skip price wait" permission, and jobs run without a person, are not limited.
 */
final class PriceWait
{
    public static function hours(): int
    {
        return max(0, (int) config('offers.price_wait_hours'));
    }

    public static function exempt(?User $user): bool
    {
        return $user === null || Access::allows($user, 'brochures', 'skip_wait');
    }

    /** When this person may next set the pack's price at the store, or null when they may now. */
    public static function until(?User $user, int $chainId, int $variantId): ?CarbonInterface
    {
        if (self::hours() === 0 || self::exempt($user)) {
            return null;
        }

        $last = Offer::query()
            ->where('chain_id', $chainId)
            ->where('product_variant_id', $variantId)
            ->max('price_set_at');
        $until = $last ? now()->parse($last)->addHours(self::hours()) : null;

        return $until?->isFuture() ? $until : null;
    }

    /** @throws ValidationException under $field, when the person has to wait */
    public static function check(?User $user, int $chainId, int $variantId, string $field = 'price'): void
    {
        if ($message = self::message($user, $chainId, $variantId)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    public static function message(?User $user, int $chainId, int $variantId): ?string
    {
        $until = self::until($user, $chainId, $variantId);
        if ($until === null) {
            return null;
        }
        $store = Chain::find($chainId)?->name ?? 'this store';
        $at = $until->copy()->setTimezone(config('app.market_timezone'))->format('j M, H:i');

        return "This pack's price at {$store} was set less than ".self::hours()." hours ago. You can change it again from {$at}, or ask our team.";
    }
}
