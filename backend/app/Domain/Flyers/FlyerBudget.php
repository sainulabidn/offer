<?php

namespace App\Domain\Flyers;

use App\Domain\Access\Access;
use App\Domain\Brochures\Brochure;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Keeps AI spend in bounds: a monthly budget (FlyerSettings monthly_budget_usd; pages wait "on hold" once it
 * is spent) and a daily page quota per person for uploads (user_daily_pages; super admins have none).
 */
final class FlyerBudget
{
    public static function spentThisMonth(): float
    {
        return (float) AiCall::query()->where('created_at', '>=', self::monthStart())->sum('cost_usd');
    }

    public static function remaining(): float
    {
        return (float) FlyerSettings::get('monthly_budget_usd') - self::spentThisMonth();
    }

    /** Can another page be sent to the AI now? */
    public static function allowsMore(): bool
    {
        return self::remaining() > 0;
    }

    /** Flyer pages this person uploaded today (market time). */
    public static function pagesToday(User $user): int
    {
        return (int) Brochure::query()
            ->where('ai_requested_by', $user->id)
            ->where('created_at', '>=', now(config('app.market_timezone'))->startOfDay())
            ->sum('page_count');
    }

    /** Why the person can't upload this many more pages today, or null when they can. */
    public static function quotaMessage(User $user, int $pages): ?string
    {
        if (Access::isSuperAdmin($user)) {
            return null;
        }
        $limit = (int) FlyerSettings::get('user_daily_pages');
        $used = self::pagesToday($user);

        return $used + $pages > $limit
            ? "You can upload {$limit} flyer pages a day and have used {$used}; this file has {$pages}. Try tomorrow or ask our team."
            : null;
    }

    public static function monthStart(): CarbonInterface
    {
        return now(config('app.market_timezone'))->startOfMonth()->utc();
    }
}
