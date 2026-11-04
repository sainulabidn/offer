<?php

namespace App\Domain\Flyers\Sources;

/**
 * The schedule side of flyer sources: queues every source due for a check (flyers:fetch, every 5 minutes;
 * each source has its own interval, an hour by default), and warns once when a store that publishes
 * regularly has had no new flyer for longer than usual (flyers:check-stale, daily).
 */
final class CheckSources
{
    /** Days without a new flyer before a source counts as stale, by how often the store publishes. */
    private const array STALE_DAYS = ['weekly' => 9, 'fortnightly' => 16, 'monthly' => 35];

    /** @return int sources queued */
    public function due(): int
    {
        $due = BrochureSource::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->pluck('id');
        foreach ($due as $id) {
            // Claimed until the job sets the real next check, so a slow queue doesn't get it twice.
            BrochureSource::whereKey($id)->update(['next_check_at' => now()->addMinutes(15)]);
            FetchBrochureSource::dispatch($id);
        }

        return $due->count();
    }

    /** @return int sources warned about */
    public function stale(): int
    {
        $warned = 0;
        $sources = BrochureSource::query()->where('is_active', true)->with('chain')->get()->filter->permitted();
        foreach ($sources as $source) {
            $days = self::STALE_DAYS[$source->chain->brochure_frequency?->value] ?? null;
            $since = $source->last_new_at ?? $source->created_at;
            if ($days === null || $since->isAfter(now()->subDays($days)) || ($source->stale_alerted_at && $source->stale_alerted_at->isAfter($since))) {
                continue;
            }
            SourceAlerts::send($source, "No new flyer from {$source->chain->name}", "{$source->name}: nothing new for ".$since->diffForHumans(syntax: true).'. The site may have moved its flyers; check the source or upload one by hand.');
            $source->update(['stale_alerted_at' => now()]);
            $warned++;
        }

        return $warned;
    }
}
